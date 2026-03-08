<?php

namespace App\Console\Commands;

use App\Services\ConfluenceExportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportTypo3ToConfluence extends Command
{
    protected $signature = 'app:export-typo3-to-confluence
        {--output= : Output directory for the ZIP file (defaults to storage/app)}
        {--space-key=INTRANET : Confluence space key}
        {--space-name=Intranet : Confluence space name}
        {--fileadmin= : Path to TYPO3 fileadmin directory}
        {--include-hidden : Include hidden pages}
        {--root-pid=0 : Root page ID to start export from}';

    protected $description = 'Export TYPO3 pages and attachments to a Confluence-compatible ZIP export';

    public function handle(): int
    {
        $outputPath = $this->option('output') ?: storage_path('app');
        $fileadminPath = $this->option('fileadmin') ?: config('app.typo3_fileadmin_path', env('TYPO3_FILEADMIN_PATH', ''));
        $spaceKey = $this->option('space-key');
        $spaceName = $this->option('space-name');
        $includeHidden = $this->option('include-hidden');
        $rootPid = (int) $this->option('root-pid');

        if (! is_dir($outputPath)) {
            $this->error("Output directory does not exist: {$outputPath}");

            return self::FAILURE;
        }

        $this->info('Connecting to TYPO3 database...');

        try {
            DB::connection('typo3')->getPdo();
        } catch (\Exception $e) {
            $this->error('Cannot connect to TYPO3 database: '.$e->getMessage());

            return self::FAILURE;
        }

        $builder = new ConfluenceExportBuilder($spaceKey, $spaceName);

        // Fetch pages
        $this->info('Fetching pages from TYPO3...');
        $pages = $this->fetchPages($rootPid, $includeHidden);
        $this->info("Found {$pages->count()} pages.");

        if ($pages->isEmpty()) {
            $this->warn('No pages found. Nothing to export.');

            return self::SUCCESS;
        }

        // Build page tree
        $bar = $this->output->createProgressBar($pages->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage('Processing pages...');
        $bar->start();

        foreach ($pages as $page) {
            $parentPid = $page->pid === $rootPid ? null : (int) $page->pid;
            $bodyHtml = $this->getPageContent((int) $page->uid);

            $builder->addPage(
                (int) $page->uid,
                $page->title ?: 'Untitled Page '.$page->uid,
                $parentPid,
                (int) ($page->sorting ?? 0),
                $bodyHtml
            );

            $bar->setMessage("Page: {$page->title}");
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Fetch and add attachments
        $this->info('Fetching attachments...');
        $attachmentCount = 0;

        foreach ($pages as $page) {
            $attachments = $this->getPageAttachments((int) $page->uid, $fileadminPath);
            foreach ($attachments as $attachment) {
                $builder->addAttachment(
                    (int) $page->uid,
                    $attachment['fileName'],
                    $attachment['fileSize'],
                    $attachment['mediaType'],
                    $attachment['filePath']
                );
                $attachmentCount++;
            }
        }

        $this->info("Found {$attachmentCount} attachments.");

        // Build the ZIP
        $this->info('Building Confluence export ZIP...');
        $zipPath = $builder->build($outputPath);

        $this->newLine();
        $this->info("Export complete: {$zipPath}");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchPages(int $rootPid, bool $includeHidden): \Illuminate\Support\Collection
    {
        $query = DB::connection('typo3')
            ->table('pages')
            ->where('deleted', 0)
            ->where('doktype', '<', 200) // Exclude system pages (folders, recycler, etc.)
            ->orderBy('pid')
            ->orderBy('sorting');

        if (! $includeHidden) {
            $query->where('hidden', 0);
        }

        if ($rootPid > 0) {
            // Get all descendant page UIDs recursively
            $pageUids = $this->getDescendantPageUids($rootPid, $includeHidden);
            $pageUids[] = $rootPid;
            $query->whereIn('uid', $pageUids);
        }

        return $query->get(['uid', 'pid', 'title', 'sorting', 'crdate', 'tstamp', 'doktype']);
    }

    /**
     * @return list<int>
     */
    private function getDescendantPageUids(int $parentUid, bool $includeHidden): array
    {
        $query = DB::connection('typo3')
            ->table('pages')
            ->where('pid', $parentUid)
            ->where('deleted', 0)
            ->where('doktype', '<', 200);

        if (! $includeHidden) {
            $query->where('hidden', 0);
        }

        $children = $query->pluck('uid')->all();
        $descendants = $children;

        foreach ($children as $childUid) {
            $descendants = array_merge($descendants, $this->getDescendantPageUids($childUid, $includeHidden));
        }

        return $descendants;
    }

    private function getPageContent(int $pageUid): string
    {
        $contentElements = DB::connection('typo3')
            ->table('tt_content')
            ->where('pid', $pageUid)
            ->where('deleted', 0)
            ->where('hidden', 0)
            ->orderBy('colPos')
            ->orderBy('sorting')
            ->get(['uid', 'header', 'bodytext', 'CType', 'header_layout']);

        if ($contentElements->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($contentElements as $ce) {
            // Add header if present
            if (! empty($ce->header) && ($ce->header_layout ?? '100') !== '100') {
                $level = match ((string) ($ce->header_layout ?? '0')) {
                    '1' => 1,
                    '2' => 2,
                    '3' => 3,
                    '4' => 4,
                    '5' => 5,
                    default => 2,
                };
                $html .= "<h{$level}>".htmlspecialchars($ce->header, ENT_XML1, 'UTF-8')."</h{$level}>\n";
            }

            // Add body text
            if (! empty($ce->bodytext)) {
                $bodytext = $ce->bodytext;

                // Convert RTE content (basic TYPO3 RTE to HTML)
                $bodytext = $this->convertTypo3RteToHtml($bodytext);

                $html .= $bodytext."\n";
            }
        }

        return trim($html);
    }

    private function convertTypo3RteToHtml(string $text): string
    {
        // TYPO3 RTE typically stores content as HTML already,
        // but may contain some TYPO3-specific markup

        // Convert TYPO3 link syntax: <link url>text</link> or t3://page?uid=X
        $text = preg_replace(
            '/<link\s+([^>]+)>(.*?)<\/link>/si',
            '<a href="$1">$2</a>',
            $text
        );

        // Convert t3:// links to plain text (since we can't resolve them in Confluence)
        $text = preg_replace(
            '/href="t3:\/\/[^"]*"/i',
            'href="#"',
            $text
        );

        // Wrap plain text blocks in paragraphs if not already wrapped
        if (! str_contains($text, '<') && ! empty(trim($text))) {
            $lines = explode("\n", $text);
            $text = implode('', array_map(
                fn ($line) => ! empty(trim($line)) ? '<p>'.htmlspecialchars($line, ENT_XML1, 'UTF-8').'</p>' : '',
                $lines
            ));
        }

        return $text;
    }

    /**
     * @return list<array{fileName: string, fileSize: int, mediaType: string, filePath: string}>
     */
    private function getPageAttachments(int $pageUid, string $fileadminPath): array
    {
        $attachments = [];

        // Get files referenced from tt_content on this page
        $references = DB::connection('typo3')
            ->table('sys_file_reference')
            ->where('deleted', 0)
            ->where('hidden', 0)
            ->where(function ($query) use ($pageUid) {
                $query->where(function ($q) use ($pageUid) {
                    $q->where('tablenames', 'tt_content')
                        ->whereIn('uid_foreign', function ($sub) use ($pageUid) {
                            $sub->select('uid')
                                ->from('tt_content')
                                ->where('pid', $pageUid)
                                ->where('deleted', 0);
                        });
                })->orWhere(function ($q) use ($pageUid) {
                    $q->where('tablenames', 'pages')
                        ->where('uid_foreign', $pageUid);
                });
            })
            ->get(['uid_local']);

        if ($references->isEmpty()) {
            return [];
        }

        $fileUids = $references->pluck('uid_local')->unique()->values();

        $files = DB::connection('typo3')
            ->table('sys_file')
            ->whereIn('uid', $fileUids)
            ->get(['uid', 'identifier', 'name', 'mime_type', 'size', 'storage']);

        foreach ($files as $file) {
            $filePath = $this->resolveFilePath($file, $fileadminPath);

            $attachments[] = [
                'fileName' => $file->name,
                'fileSize' => (int) $file->size,
                'mediaType' => $file->mime_type ?: 'application/octet-stream',
                'filePath' => $filePath,
            ];
        }

        return $attachments;
    }

    private function resolveFilePath(object $file, string $fileadminPath): string
    {
        // TYPO3 stores file identifiers like /user_upload/document.pdf
        // The actual file is at fileadmin/user_upload/document.pdf
        $identifier = ltrim($file->identifier, '/');

        if (! empty($fileadminPath)) {
            return rtrim($fileadminPath, '/').'/'.$identifier;
        }

        // Try to resolve from sys_file_storage
        $storage = DB::connection('typo3')
            ->table('sys_file_storage')
            ->where('uid', $file->storage)
            ->first(['configuration']);

        if ($storage && ! empty($storage->configuration)) {
            // TYPO3 stores this as FlexForm XML
            if (preg_match('/<field index="basePath">\s*<value[^>]*>(.*?)<\/value>/s', $storage->configuration, $matches)) {
                $basePath = trim($matches[1]);

                return rtrim($basePath, '/').'/'.$identifier;
            }
        }

        // Fallback: assume standard fileadmin location
        return 'fileadmin/'.$identifier;
    }
}
