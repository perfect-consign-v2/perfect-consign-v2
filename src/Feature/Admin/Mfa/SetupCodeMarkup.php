<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;
use RuntimeException;

/**
 * Turns a library SVG into one svg of black paths, or refuses it.
 * The result has no style, script, link, or secret text.
 */
final class SetupCodeMarkup
{
    private const FAILURE = 'the authenticator image could not be drawn';

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const MAX_MARKUP = 262144;

    public function image(string $markup, string $secret): string
    {
        $svg = $this->root($markup, $secret);
        $paths = $this->paths($svg);
        if ($paths === []) {
            throw new RuntimeException(self::FAILURE);
        }

        return $this->rebuild($svg->getAttribute('viewBox'), $paths);
    }

    private function root(string $markup, string $secret): DOMElement
    {
        if ($this->blocked($markup, $secret)) {
            throw new RuntimeException(self::FAILURE);
        }
        $dom = $this->load($markup);
        $root = $dom?->documentElement;
        if (!$root instanceof DOMElement || !$this->singleSvg($dom, $root)) {
            throw new RuntimeException(self::FAILURE);
        }

        return $root;
    }

    private function blocked(string $markup, string $secret): bool
    {
        if ($markup === '' || strlen($markup) > self::MAX_MARKUP || $this->leaks($markup, $secret)) {
            return true;
        }

        return preg_match('/<script|<style|foreignobject|<iframe|<!--|<!doctype|<\?| href| xlink| style| on/i', $markup) === 1;
    }

    private function leaks(string $markup, string $secret): bool
    {
        return $secret !== '' && str_contains(strtolower($markup), strtolower($secret));
    }

    private function load(string $markup): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($markup, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded === true ? $dom : null;
    }

    private function singleSvg(?DOMDocument $dom, DOMElement $root): bool
    {
        if (!$dom instanceof DOMDocument || !$this->oneElement($dom)) {
            return false;
        }

        return $this->svgShape($root);
    }

    private function oneElement(DOMDocument $dom): bool
    {
        $seen = false;
        foreach ($dom->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($seen) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    private function svgShape(DOMElement $svg): bool
    {
        if ($svg->localName !== 'svg' || $svg->namespaceURI !== self::SVG_NS) {
            return false;
        }
        if ($svg->getAttribute('preserveAspectRatio') !== 'xMidYMid') {
            return false;
        }
        if (preg_match('/\A[0-9]+ [0-9]+ [0-9]+ [0-9]+\z/', $svg->getAttribute('viewBox')) !== 1) {
            return false;
        }

        return $this->only($svg, ['xmlns', 'class', 'viewBox', 'preserveAspectRatio']);
    }

    /**
     * @param list<string> $allowed
     */
    private function only(DOMElement $element, array $allowed): bool
    {
        $attributes = $element->attributes;
        $index = 0;
        while ($index < $attributes->length) {
            $attribute = $attributes->item($index);
            $index++;
            if (!$attribute instanceof DOMAttr || $this->named($attribute, $allowed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $allowed
     */
    private function named(DOMAttr $attribute, array $allowed): bool
    {
        return str_starts_with(strtolower($attribute->name), 'on') || !in_array($attribute->name, $allowed, true);
    }

    /**
     * @return list<string>
     */
    private function paths(DOMElement $svg): array
    {
        $paths = [];
        $child = $svg->firstChild;
        while ($child !== null) {
            $data = $this->datum($child);
            if ($data === null) {
                return [];
            }
            if ($data !== '') {
                $paths[] = $data;
            }
            $child = $child->nextSibling;
        }

        return $paths;
    }

    private function datum(object $child): ?string
    {
        if ($child instanceof DOMText) {
            return trim($child->textContent) === '' ? '' : null;
        }
        if (!$child instanceof DOMElement) {
            return null;
        }

        return $this->pathDatum($child);
    }

    private function pathDatum(DOMElement $path): ?string
    {
        if ($path->localName !== 'path' || $path->namespaceURI !== self::SVG_NS) {
            return null;
        }
        if (!$this->only($path, ['class', 'fill', 'd'])) {
            return null;
        }
        $data = $path->getAttribute('d');
        if (strtolower($path->getAttribute('fill')) !== '#000' || !$this->drawing($data)) {
            return null;
        }

        return $data;
    }

    private function drawing(string $data): bool
    {
        return preg_match('/\A(?=.*M)[MmHhVvZz0-9 .\-]+\z/', $data) === 1;
    }

    /**
     * @param list<string> $paths
     */
    private function rebuild(string $viewBox, array $paths): string
    {
        $image = '<svg xmlns="' . self::SVG_NS . '" viewBox="' . $viewBox . '" preserveAspectRatio="xMidYMid">' . "\n";
        foreach ($paths as $path) {
            $image .= '<path fill="#000" d="' . $path . '"/>' . "\n";
        }

        return $image . '</svg>';
    }
}
