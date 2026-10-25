<?php

/*
 * Respaldo si el hosting no tiene activa la extensión mbstring de PHP (cPanel → Seleccionar versión
 * de PHP → Extensiones → mbstring). Laravel la necesita y symfony/polyfill-mbstring cubre casi todas
 * sus funciones, pero no mb_strimwidth (la usa Str::limit para recortar textos). Con mbstring activa,
 * este archivo no hace nada.
 */
if (! function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $texto, int $inicio, int $ancho, string $marca = '', ?string $codificacion = null): string
    {
        $texto = mb_substr($texto, $inicio, null, $codificacion);
        if (mb_strwidth($texto, $codificacion) <= $ancho) {
            return $texto;
        }
        $ancho -= mb_strwidth($marca, $codificacion);
        $resultado = '';
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $caracter) {
            if (mb_strwidth($resultado.$caracter, $codificacion) > $ancho) {
                break;
            }
            $resultado .= $caracter;
        }

        return $resultado.$marca;
    }
}
