<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use ZipArchive;

class ConfluenceExportBuilder
{
    private DOMDocument $doc;

    private DOMElement $root;

    private int $objectId = 1;

    private string $spaceKey;

    private string $spaceName;

    private int $spaceId;

    /** @var array<int, array{id: int, title: string, parentId: int|null, position: int}> */
    private array $pages = [];

    /** @var array<int, string> */
    private array $bodyContents = [];

    /** @var array<int, list<array{id: int, fileName: string, fileSize: int, mediaType: string, filePath: string}>> */
    private array $attachments = [];

    public function __construct(string $spaceKey = 'INTRANET', string $spaceName = 'Intranet')
    {
        $this->spaceKey = $spaceKey;
        $this->spaceName = $spaceName;
        $this->spaceId = $this->nextId();

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $this->root = $this->doc->createElement('hibernate-generic');
        $this->root->setAttribute('datetime', now()->format('Y-m-d H:i:s'));
        $this->doc->appendChild($this->root);
    }

    public function addPage(int $typo3Uid, string $title, ?int $parentTypo3Uid, int $position, string $bodyHtml): int
    {
        $pageId = $this->nextId();

        $this->pages[$typo3Uid] = [
            'id' => $pageId,
            'title' => $title,
            'parentId' => $parentTypo3Uid,
            'position' => $position,
        ];

        $this->bodyContents[$typo3Uid] = $bodyHtml;

        return $pageId;
    }

    public function addAttachment(int $pageTypo3Uid, string $fileName, int $fileSize, string $mediaType, string $filePath): void
    {
        $this->attachments[$pageTypo3Uid][] = [
            'id' => $this->nextId(),
            'fileName' => $fileName,
            'fileSize' => $fileSize,
            'mediaType' => $mediaType,
            'filePath' => $filePath,
        ];
    }

    public function build(string $outputPath): string
    {
        $this->buildSpaceObject();
        $this->buildSpaceDescription();

        foreach ($this->pages as $typo3Uid => $page) {
            $this->buildPageObject($typo3Uid, $page);
            $this->buildBodyContent($typo3Uid);

            if (isset($this->attachments[$typo3Uid])) {
                foreach ($this->attachments[$typo3Uid] as $attachment) {
                    $this->buildAttachmentObject($typo3Uid, $attachment);
                }
            }
        }

        $xmlContent = $this->doc->saveXML();

        $zip = new ZipArchive;
        $zipPath = rtrim($outputPath, '/').'/confluence-export.zip';

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP file at: {$zipPath}");
        }

        $zip->addFromString('entities.xml', $xmlContent);

        foreach ($this->attachments as $typo3Uid => $pageAttachments) {
            $pageId = $this->pages[$typo3Uid]['id'];
            foreach ($pageAttachments as $attachment) {
                if (file_exists($attachment['filePath'])) {
                    $version = 1;
                    $attachmentDir = "attachments/{$pageId}/{$attachment['id']}";
                    $zip->addFile($attachment['filePath'], "{$attachmentDir}/{$version}");
                }
            }
        }

        $zip->close();

        return $zipPath;
    }

    private function buildSpaceObject(): void
    {
        $object = $this->createObject('Space', $this->spaceId);

        $this->addProperty($object, 'key', $this->spaceKey);
        $this->addProperty($object, 'name', $this->spaceName);
        $this->addProperty($object, 'spaceType', 'global', 'SpaceType');

        $homePage = $this->findHomePage();
        if ($homePage !== null) {
            $this->addIdProperty($object, 'homePage', $homePage['id'], 'Page');
        }
    }

    private function buildSpaceDescription(): void
    {
        $descId = $this->nextId();
        $object = $this->createObject('SpaceDescription', $descId);

        $this->addProperty($object, 'status', 'current');
        $this->addIdProperty($object, 'space', $this->spaceId, 'Space');

        $bodyId = $this->nextId();
        $bodyObject = $this->createObject('BodyContent', $bodyId);
        $this->addProperty($bodyObject, 'body', $this->spaceName);
        $this->addProperty($bodyObject, 'bodyType', '0', 'int');
        $this->addIdProperty($bodyObject, 'content', $descId, 'SpaceDescription');
    }

    /**
     * @param  array{id: int, title: string, parentId: int|null, position: int}  $page
     */
    private function buildPageObject(int $typo3Uid, array $page): void
    {
        $object = $this->createObject('Page', $page['id']);

        $this->addProperty($object, 'title', $page['title']);
        $this->addProperty($object, 'status', 'current');
        $this->addProperty($object, 'position', (string) $page['position'], 'int');
        $this->addProperty($object, 'version', '1', 'int');
        $this->addProperty($object, 'creationDate', now()->format('Y-m-d H:i:s.v'));
        $this->addProperty($object, 'lastModificationDate', now()->format('Y-m-d H:i:s.v'));

        $this->addIdProperty($object, 'space', $this->spaceId, 'Space');

        if ($page['parentId'] !== null && isset($this->pages[$page['parentId']])) {
            $this->addIdProperty($object, 'parent', $this->pages[$page['parentId']]['id'], 'Page');
        }

        // Children collection
        $children = array_filter($this->pages, fn ($p) => $p['parentId'] === $typo3Uid);
        if (! empty($children)) {
            $collectionEl = $this->doc->createElement('collection');
            $collectionEl->setAttribute('name', 'children');
            $collectionEl->setAttribute('class', 'java.util.ArrayList');
            foreach ($children as $child) {
                $this->addCollectionElement($collectionEl, 'Page', $child['id']);
            }
            $object->appendChild($collectionEl);
        }

        // Attachments collection
        if (isset($this->attachments[$typo3Uid])) {
            $collectionEl = $this->doc->createElement('collection');
            $collectionEl->setAttribute('name', 'attachments');
            $collectionEl->setAttribute('class', 'java.util.ArrayList');
            foreach ($this->attachments[$typo3Uid] as $attachment) {
                $this->addCollectionElement($collectionEl, 'Attachment', $attachment['id']);
            }
            $object->appendChild($collectionEl);
        }
    }

    private function buildBodyContent(int $typo3Uid): void
    {
        $bodyId = $this->nextId();
        $object = $this->createObject('BodyContent', $bodyId);

        $body = $this->bodyContents[$typo3Uid] ?? '';
        $this->addProperty($object, 'body', $body);
        $this->addProperty($object, 'bodyType', '0', 'int');
        $this->addIdProperty($object, 'content', $this->pages[$typo3Uid]['id'], 'Page');
    }

    /**
     * @param  array{id: int, fileName: string, fileSize: int, mediaType: string, filePath: string}  $attachment
     */
    private function buildAttachmentObject(int $pageTypo3Uid, array $attachment): void
    {
        $object = $this->createObject('Attachment', $attachment['id']);

        $this->addProperty($object, 'title', $attachment['fileName']);
        $this->addProperty($object, 'status', 'current');
        $this->addProperty($object, 'version', '1', 'int');
        $this->addProperty($object, 'fileSize', (string) $attachment['fileSize'], 'long');
        $this->addProperty($object, 'mediaType', $attachment['mediaType']);
        $this->addProperty($object, 'contentType', $attachment['mediaType']);
        $this->addProperty($object, 'creationDate', now()->format('Y-m-d H:i:s.v'));
        $this->addProperty($object, 'lastModificationDate', now()->format('Y-m-d H:i:s.v'));

        $this->addIdProperty($object, 'space', $this->spaceId, 'Space');
        $this->addIdProperty($object, 'container', $this->pages[$pageTypo3Uid]['id'], 'Page');
    }

    private function createObject(string $class, int $id): DOMElement
    {
        $object = $this->doc->createElement('object');
        $object->setAttribute('class', $class);
        $object->setAttribute('package', '');
        $this->root->appendChild($object);

        $idEl = $this->doc->createElement('id');
        $idEl->setAttribute('name', 'id');
        $idEl->appendChild($this->doc->createTextNode((string) $id));
        $object->appendChild($idEl);

        return $object;
    }

    private function addProperty(DOMElement $parent, string $name, string $value, ?string $type = null): void
    {
        $prop = $this->doc->createElement('property');
        $prop->setAttribute('name', $name);
        if ($type !== null) {
            $prop->setAttribute('type', $type);
        }

        if ($name === 'body') {
            $prop->appendChild($this->doc->createCDATASection($value));
        } else {
            $prop->appendChild($this->doc->createTextNode($value));
        }

        $parent->appendChild($prop);
    }

    private function addIdProperty(DOMElement $parent, string $name, int $id, string $class): void
    {
        $prop = $this->doc->createElement('property');
        $prop->setAttribute('name', $name);
        $prop->setAttribute('class', $class);
        $prop->setAttribute('package', '');

        $idEl = $this->doc->createElement('id');
        $idEl->setAttribute('name', 'id');
        $idEl->appendChild($this->doc->createTextNode((string) $id));
        $prop->appendChild($idEl);

        $parent->appendChild($prop);
    }

    private function addCollectionElement(DOMElement $collection, string $class, int $id): void
    {
        $element = $this->doc->createElement('element');
        $element->setAttribute('class', $class);
        $element->setAttribute('package', '');

        $idProp = $this->doc->createElement('id');
        $idProp->setAttribute('name', 'id');
        $idProp->appendChild($this->doc->createTextNode((string) $id));
        $element->appendChild($idProp);

        $collection->appendChild($element);
    }

    /**
     * @return array{id: int, title: string, parentId: int|null, position: int}|null
     */
    private function findHomePage(): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['parentId'] === null || $page['parentId'] === 0) {
                return $page;
            }
        }

        return null;
    }

    private function nextId(): int
    {
        return $this->objectId++;
    }
}
