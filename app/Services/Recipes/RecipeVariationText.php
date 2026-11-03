<?php

namespace App\Services\Recipes;

use DOMDocument;
use DOMXPath;

class RecipeVariationText
{
    /** Copies text only; source embeds, captions, media references and remote addresses are omitted. */
    public function clean(?string $text): string
    {
        if (blank($text)) {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div>'.$text.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//img|//video|//audio|//source|//iframe|//object|//embed|//script|//style|//svg|//figure|//*[@data-media-id]|//*[@data-id]') as $node) {
                $node->parentNode?->removeChild($node);
            }
            $text = $document->textContent;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return trim(preg_replace('~https?://[^\s<>]+~iu', '', $text));
    }
}
