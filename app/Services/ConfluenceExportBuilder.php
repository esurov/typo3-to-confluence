<?php

namespace App\Services;

use Illuminate\Support\Str;
use ZipArchive;

class ConfluenceExportBuilder
{
    private string $spaceName;

    /** @var array<int, array{title: string, parentId: int|null, position: int, slug: string}> */
    private array $pages = [];

    /** @var array<int, string> */
    private array $bodyContents = [];

    /** @var array<int, list<array{fileName: string, fileSize: int, mediaType: string, filePath: string}>> */
    private array $attachments = [];

    /** @var array<string, int> Track slug usage to avoid duplicates */
    private array $slugCounts = [];

    public function __construct(string $spaceName = 'Intranet')
    {
        $this->spaceName = $spaceName;
    }

    public function addPage(int $typo3Uid, string $title, ?int $parentTypo3Uid, int $position, string $bodyHtml): void
    {
        $slug = $this->generateUniqueSlug($title, $typo3Uid);

        $this->pages[$typo3Uid] = [
            'title' => $title,
            'parentId' => $parentTypo3Uid,
            'position' => $position,
            'slug' => $slug,
        ];

        $this->bodyContents[$typo3Uid] = $bodyHtml;
    }

    public function addAttachment(int $pageTypo3Uid, string $fileName, int $fileSize, string $mediaType, string $filePath): void
    {
        $this->attachments[$pageTypo3Uid][] = [
            'fileName' => $fileName,
            'fileSize' => $fileSize,
            'mediaType' => $mediaType,
            'filePath' => $filePath,
        ];
    }

    public function build(string $outputPath): string
    {
        $zip = new ZipArchive;
        $zipPath = rtrim($outputPath, '/').'/confluence-export.zip';

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP file at: {$zipPath}");
        }

        // Build index.html (page tree / table of contents)
        $zip->addFromString('index.html', $this->buildIndexHtml());

        // Build individual page HTML files and collect attachments
        foreach ($this->pages as $typo3Uid => $page) {
            $pageHtml = $this->buildPageHtml($typo3Uid, $page);
            $zip->addFromString($page['slug'].'.html', $pageHtml);

            // Add attachments for this page
            if (isset($this->attachments[$typo3Uid])) {
                foreach ($this->attachments[$typo3Uid] as $attachment) {
                    $attachmentZipPath = 'attachments/'.$page['slug'].'/'.$attachment['fileName'];
                    if (file_exists($attachment['filePath'])) {
                        $zip->addFile($attachment['filePath'], $attachmentZipPath);
                    }
                }
            }
        }

        // Add a basic stylesheet
        $zip->addFromString('styles/site.css', $this->buildCss());

        $zip->close();

        return $zipPath;
    }

    private function buildIndexHtml(): string
    {
        $tree = $this->buildPageTreeHtml();

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>{$this->escape($this->spaceName)}</title>
            <link rel="stylesheet" type="text/css" href="styles/site.css">
        </head>
        <body>
            <div id="main-header">
                <h1>{$this->escape($this->spaceName)}</h1>
            </div>
            <div id="content">
                <h2>Pages</h2>
                {$tree}
            </div>
        </body>
        </html>
        HTML;
    }

    private function buildPageTreeHtml(): string
    {
        // Find root pages (no parent or parent not in our set)
        $rootPages = [];
        foreach ($this->pages as $uid => $page) {
            if ($page['parentId'] === null || ! isset($this->pages[$page['parentId']])) {
                $rootPages[$uid] = $page;
            }
        }

        // Sort by position
        uasort($rootPages, fn ($a, $b) => $a['position'] <=> $b['position']);

        return $this->buildTreeList($rootPages);
    }

    /**
     * @param  array<int, array{title: string, parentId: int|null, position: int, slug: string}>  $pages
     */
    private function buildTreeList(array $pages): string
    {
        if (empty($pages)) {
            return '';
        }

        $html = "<ul>\n";
        foreach ($pages as $uid => $page) {
            $html .= '<li><a href="'.$this->escape($page['slug']).'.html">'.$this->escape($page['title'])."</a>\n";

            // Find children
            $children = array_filter($this->pages, fn ($p) => $p['parentId'] === $uid);
            uasort($children, fn ($a, $b) => $a['position'] <=> $b['position']);

            if (! empty($children)) {
                $html .= $this->buildTreeList($children);
            }

            $html .= "</li>\n";
        }
        $html .= "</ul>\n";

        return $html;
    }

    /**
     * @param  array{title: string, parentId: int|null, position: int, slug: string}  $page
     */
    private function buildPageHtml(int $typo3Uid, array $page): string
    {
        $title = $this->escape($page['title']);
        $body = $this->bodyContents[$typo3Uid] ?? '';

        // Build breadcrumb
        $breadcrumb = $this->buildBreadcrumb($typo3Uid);

        // Build attachment list
        $attachmentHtml = $this->buildAttachmentListHtml($typo3Uid, $page['slug']);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>{$title}</title>
            <link rel="stylesheet" type="text/css" href="styles/site.css">
        </head>
        <body>
            <div id="main-header">
                <h1><a href="index.html">{$this->escape($this->spaceName)}</a></h1>
            </div>
            <div id="breadcrumb-section">
                {$breadcrumb}
            </div>
            <div id="content">
                <div id="main-content" class="wiki-content">
                    <h1>{$title}</h1>
                    {$body}
                </div>
                {$attachmentHtml}
            </div>
        </body>
        </html>
        HTML;
    }

    private function buildBreadcrumb(int $typo3Uid): string
    {
        $crumbs = [];
        $currentUid = $typo3Uid;

        while (isset($this->pages[$currentUid])) {
            $page = $this->pages[$currentUid];
            array_unshift($crumbs, $page);

            if ($page['parentId'] === null || ! isset($this->pages[$page['parentId']])) {
                break;
            }
            $currentUid = $page['parentId'];
        }

        $html = '<ol>';
        foreach ($crumbs as $crumb) {
            $html .= '<li><a href="'.$this->escape($crumb['slug']).'.html">'.$this->escape($crumb['title']).'</a></li>';
        }
        $html .= '</ol>';

        return $html;
    }

    private function buildAttachmentListHtml(int $typo3Uid, string $pageSlug): string
    {
        if (! isset($this->attachments[$typo3Uid]) || empty($this->attachments[$typo3Uid])) {
            return '';
        }

        $html = "<div id=\"attachments\">\n<h2>Attachments</h2>\n<ul>\n";

        foreach ($this->attachments[$typo3Uid] as $attachment) {
            $href = 'attachments/'.$this->escape($pageSlug).'/'.$this->escape($attachment['fileName']);
            $html .= '<li><a href="'.$href.'">'.$this->escape($attachment['fileName']).'</a></li>'."\n";
        }

        $html .= "</ul>\n</div>\n";

        return $html;
    }

    private function buildCss(): string
    {
        return <<<'CSS'
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 20px; color: #333; }
        #main-header { border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        #main-header h1 { margin: 0; }
        #main-header a { text-decoration: none; color: #333; }
        #breadcrumb-section ol { list-style: none; padding: 0; display: flex; gap: 5px; }
        #breadcrumb-section ol li:not(:last-child)::after { content: " > "; margin-left: 5px; }
        #content { max-width: 960px; }
        .wiki-content { line-height: 1.6; }
        #attachments { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
        ul { padding-left: 20px; }
        a { color: #0052CC; }
        CSS;
    }

    private function generateUniqueSlug(string $title, int $uid): string
    {
        $slug = Str::slug($title);

        if (empty($slug)) {
            $slug = 'page-'.$uid;
        }

        if (isset($this->slugCounts[$slug])) {
            $this->slugCounts[$slug]++;
            $slug .= '-'.$this->slugCounts[$slug];
        } else {
            $this->slugCounts[$slug] = 1;
        }

        return $slug;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
