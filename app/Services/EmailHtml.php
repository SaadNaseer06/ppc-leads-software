<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Formatted emails. What the editor sends is cut down to the formatting it offers (bold, italic,
 * underline, lists, links), so nothing else (scripts, styles, tracking images) is ever stored, shown in
 * the app or sent. Every email also gets a plain-text version, for mail apps that don't show HTML and for
 * the app's own previews and search.
 */
class EmailHtml
{
    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(500_000);

        return trim((new HtmlSanitizer($config))->sanitizeFor('body', $html));
    }

    /** The same email as plain text: paragraphs and lines kept, lists bulleted, links written out */
    public static function text(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        $text = $body ? self::walk($body) : '';

        // No more than one blank line in a row, and no stray spaces at line ends
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** Wraps the email in a plain, readable font, as mail apps show it */
    public static function document(string $html): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            .'<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937">'
            .$html
            .'</div></body></html>';
    }

    private static function walk(DOMNode $node, string $prefix = ''): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= preg_replace('/\s+/u', ' ', $child->nodeValue);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $inner = self::walk($child, $prefix);
            $out .= match ($tag) {
                'br' => "\n",
                'p' => "\n".trim($inner, ' ')."\n\n",
                'blockquote' => "\n".preg_replace('/^/m', '> ', trim($inner))."\n\n",
                'ul', 'ol' => "\n".self::items($child, $tag === 'ol')."\n",
                'a' => self::link($child, $inner),
                default => $inner,
            };
        }

        return $out;
    }

    private static function items(DOMElement $list, bool $numbered): string
    {
        $lines = [];
        $n = 1;
        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }
            $text = trim(preg_replace("/\n{2,}/", "\n", self::walk($item)));
            $marker = $numbered ? ($n++).'. ' : '• ';
            // A wrapped item's later lines line up under its first
            $lines[] = $marker.str_replace("\n", "\n".str_repeat(' ', mb_strlen($marker)), $text);
        }

        return implode("\n", $lines)."\n";
    }

    private static function link(DOMElement $a, string $inner): string
    {
        $href = $a->getAttribute('href');
        $label = trim($inner);
        $target = str_starts_with($href, 'mailto:') ? substr($href, 7) : $href;
        if ($href === '' || $label === $target || $label === $href) {
            return $inner;
        }

        return $label === '' ? $target : "{$label} ({$target})";
    }
}
