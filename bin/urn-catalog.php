<?php

declare(strict_types=1);

/*
 * Maps Magento's urn:magento:* schema locations to files for PhpStorm, as bin/magento
 * dev:urn-catalog:generate would in a full install. This repo has no bin/magento, but composer's
 * autoloader runs every vendored registration.php, so core's UrnResolver can still resolve them.
 */

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Config\Dom\UrnResolver;

$root = dirname(__DIR__);
$miscXml = $root . '/.idea/misc.xml';

// Runs as a composer hook, including on CI where there is no IDE to configure
if (!is_dir(dirname($miscXml))) {
    echo "No .idea directory, skipping the PhpStorm URN catalog.\n";
    exit(0);
}

require $root . '/vendor/autoload.php';

$registrar = new ComponentRegistrar();
$componentPaths = [];
foreach ([ComponentRegistrar::MODULE, ComponentRegistrar::LIBRARY, ComponentRegistrar::SETUP] as $type) {
    $componentPaths = [...$componentPaths, ...array_values($registrar->getPaths($type))];
}

// Schemas pull each other in by URN too, so every shipped XSD is scanned alongside this module's XML
$urns = [];
foreach (array_unique($componentPaths) as $componentPath) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($componentPath, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['xsd', 'xml'], true)) {
            continue;
        }
        // Only our own XML matters; vendored XML is never opened for editing
        if ($file->getExtension() === 'xml' && !str_starts_with($file->getPathname(), $root . '/src/')) {
            continue;
        }

        preg_match_all('/schemaLocation="(urn:magento:[^"]+)"/i', (string)file_get_contents($file->getPathname()), $matches);
        $urns = [...$urns, ...$matches[1]];
    }
}

$resolver = new UrnResolver();
$locations = [];
foreach (array_unique($urns) as $urn) {
    try {
        $path = (string)realpath($resolver->getRealPath($urn));
    } catch (Exception) {
        // A URN for a package this repo does not install
        continue;
    }
    $locations[$urn] = str_starts_with($path, $root . '/') ? '$PROJECT_DIR$' . substr($path, strlen($root)) : $path;
}
ksort($locations);

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
if (!is_file($miscXml) || !$dom->loadXML((string)file_get_contents($miscXml))) {
    $dom->loadXML('<project version="4"/>');
}

$xpath = new DOMXPath($dom);
$component = $xpath->query("/project/component[@name='ProjectResources']")?->item(0);
if (!$component instanceof DOMElement) {
    $component = $dom->createElement('component');
    $component->setAttribute('name', 'ProjectResources');
    $dom->documentElement?->appendChild($component);
}

// Replace rather than append, so reruns do not pile up duplicates; other resources are left alone
foreach (iterator_to_array($xpath->query("resource[starts-with(@url, 'urn:magento:')]", $component) ?: []) as $old) {
    $component->removeChild($old);
}
foreach ($locations as $urn => $location) {
    $resource = $dom->createElement('resource');
    $resource->setAttribute('url', $urn);
    $resource->setAttribute('location', $location);
    $component->appendChild($resource);
}

file_put_contents($miscXml, $dom->saveXML());
printf("Mapped %d Magento schema URNs in %s\n", count($locations), $miscXml);
