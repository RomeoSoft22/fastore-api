<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Descripciones de producto en HTML (editor del admin). Solo se conservan las etiquetas y atributos
 * de la lista; todo lo demás (scripts, estilos, eventos on*, iframes, javascript:) se elimina.
 * Así el HTML que se muestra en la tienda no puede inyectar código.
 */
class Html
{
    private const ETIQUETAS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'], 'mark' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    ];

    private const ALINEACIONES = ['left', 'center', 'right', 'justify'];

    public static function limpiar(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        // Texto heredado sin etiquetas: párrafos por línea en blanco y <br> por salto de línea.
        if (! preg_match('/<[a-z][^>]*>/i', $html)) {
            return self::desdeTexto($html);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="raiz">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $raiz = $dom->getElementById('raiz');
        self::filtrar($raiz);

        $salida = '';
        foreach ($raiz->childNodes as $hijo) {
            $salida .= $dom->saveHTML($hijo);
        }

        return trim($salida);
    }

    public static function desdeTexto(string $texto): string
    {
        $parrafos = preg_split("/\n\s*\n/u", str_replace("\r", '', trim($texto)));

        return implode('', array_map(
            fn ($p) => '<p>'.nl2br(e(trim(preg_replace('/[ \t]+\n/', "\n", $p))), false).'</p>',
            array_filter($parrafos, fn ($p) => trim($p) !== ''),
        ));
    }

    /** Texto plano para resúmenes, meta description y JSON-LD. */
    public static function texto(?string $html): string
    {
        $html = preg_replace('#<(br|/p|/li|/h[2-4])\s*/?>#i', "$0\n", (string) $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function filtrar(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (! $hijo instanceof DOMElement) {
                $nodo->removeChild($hijo); // comentarios, instrucciones de proceso…

                continue;
            }

            $etiqueta = strtolower($hijo->tagName);
            if (in_array($etiqueta, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math'], true)) {
                $nodo->removeChild($hijo);

                continue;
            }
            self::filtrar($hijo);

            if (! isset(self::ETIQUETAS[$etiqueta])) {
                // Etiqueta no permitida (div, span, font…): se conserva su contenido.
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);

                continue;
            }

            $alineacion = null;
            if (preg_match('/text-align:\s*(left|center|right|justify)/i', $hijo->getAttribute('style'), $m)) {
                $alineacion = strtolower($m[1]);
            }
            foreach (iterator_to_array($hijo->attributes) as $atributo) {
                if (! in_array(strtolower($atributo->name), self::ETIQUETAS[$etiqueta], true)) {
                    $hijo->removeAttribute($atributo->name);
                }
            }
            if ($alineacion && in_array($etiqueta, ['p', 'h2', 'h3', 'h4'], true) && in_array($alineacion, self::ALINEACIONES, true)) {
                $hijo->setAttribute('style', 'text-align: '.$alineacion);
            }

            if ($etiqueta === 'a') {
                $href = trim($hijo->getAttribute('href'));
                if (! preg_match('#^(https?://|mailto:|tel:|/)#i', $href)) {
                    $hijo->removeAttribute('href');
                } elseif (preg_match('#^https?://#i', $href) && ! str_starts_with($href, config('tienda.url'))) {
                    // Enlaces externos: pestaña nueva y sin transmitir autoridad ni la página de origen.
                    $hijo->setAttribute('target', '_blank');
                    $hijo->setAttribute('rel', 'nofollow noopener noreferrer');
                }
            }
            if ($etiqueta === 'img') {
                $src = trim($hijo->getAttribute('src'));
                if (! preg_match('#^https?://#i', $src)) {
                    $nodo->removeChild($hijo);

                    continue;
                }
                $hijo->setAttribute('loading', 'lazy');
            }
        }
    }
}
