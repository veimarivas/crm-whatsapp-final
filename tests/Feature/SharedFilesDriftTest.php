<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * D3 (red provisional) — los archivos que deben ser idénticos en los dos
 * proyectos no se separan sin que nadie se entere.
 *
 * 36 archivos —Breeze completo, componentes de UI, hooks— existen por
 * duplicado en este repo y en el Komo sin ninguna razón para ser dos. La
 * solución definitiva es que vivan **una** vez, en el paquete compartido de D3;
 * eso está bloqueado por dónde alojar el paquete de npm, porque el build corre
 * en el VPS.
 *
 * **Esto NO es D3, es su red.** Mientras el paquete no exista, al menos que la
 * deriva no pase inadvertida — que es exactamente lo que le pasó a la capa de
 * gráficos: se creó explícitamente como «una sola» y en un mes tenía dos
 * `format.js`, dos `chartTheme.js` y dos `TrendArea.jsx` distintos. Nadie lo
 * notó porque nada lo comprobaba.
 *
 * **Por qué un test y no un comando:** un comando hay que acordarse de
 * correrlo, que es la misma debilidad que tiene la convención escrita. La suite
 * se corre antes de cada deploy igual, así que la deriva aparece sola.
 *
 * En desarrollo los dos repos están uno al lado del otro (`htdocs/`), que es
 * donde este test sirve. En el VPS y en CI el hermano no está y **el test se
 * salta diciendo por qué**, en vez de fallar por algo que no es culpa del
 * código.
 */
class SharedFilesDriftTest extends TestCase
{
    /** Nombre de carpeta del repo hermano; `TWIN_REPO_PATH` lo puede pisar. */
    private const TWIN_DIRNAME = 'laravel_komo_crm';

    private function twinPath(): ?string
    {
        $candidate = env('TWIN_REPO_PATH') ?: dirname(base_path()).DIRECTORY_SEPARATOR.self::TWIN_DIRNAME;

        return is_dir($candidate) ? rtrim($candidate, DIRECTORY_SEPARATOR) : null;
    }

    /** @return array<int, string> */
    private function manifest(): array
    {
        $path = base_path('tests/Fixtures/twins/shared-files.json');

        $this->assertFileExists($path, 'Falta el manifiesto de archivos compartidos.');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['files'];
    }

    public function test_los_archivos_compartidos_siguen_siendo_identicos(): void
    {
        $twin = $this->twinPath();

        if ($twin === null) {
            $this->markTestSkipped(
                'No está el repo hermano al lado (se buscó ../'.self::TWIN_DIRNAME.'). '
                .'Es lo normal en el VPS y en CI; en desarrollo, si querés que corra, '
                .'apuntá TWIN_REPO_PATH a la carpeta del Komo.'
            );
        }

        $divergidos = [];
        $faltantes = [];

        foreach ($this->manifest() as $relative) {
            $mine = base_path($relative);
            $theirs = $twin.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_file($mine) || ! is_file($theirs)) {
                $faltantes[] = $relative;

                continue;
            }

            if (hash_file('sha256', $mine) !== hash_file('sha256', $theirs)) {
                $divergidos[] = $relative;
            }
        }

        $this->assertSame([], $faltantes,
            "Estos archivos del manifiesto ya no existen en los dos repos. Si se movieron o se \n"
            ."borraron a propósito, sacalos del manifiesto EN LOS DOS:\n  - "
            .implode("\n  - ", $faltantes)
        );

        $this->assertSame([], $divergidos,
            "Estos archivos deberían ser idénticos en los dos proyectos y se separaron.\n"
            ."Elegí cuál versión gana, copiala al otro repo, y si la diferencia es deliberada\n"
            ."sacá el archivo del manifiesto EN LOS DOS diciendo por qué:\n  - "
            .implode("\n  - ", $divergidos)
        );
    }

    public function test_el_manifiesto_es_el_mismo_de_los_dos_lados(): void
    {
        $twin = $this->twinPath();

        if ($twin === null) {
            $this->markTestSkipped('No está el repo hermano al lado.');
        }

        $theirs = $twin.'/tests/Fixtures/twins/shared-files.json';

        if (! is_file($theirs)) {
            $this->markTestSkipped('El repo hermano todavía no tiene el manifiesto (deploy a medias).');
        }

        // Sin esto, alguien saca un archivo de la lista de un solo lado y la
        // red deja de cubrirlo sin que se note: exactamente la falla que este
        // test existe para evitar.
        $this->assertSame(
            hash_file('sha256', base_path('tests/Fixtures/twins/shared-files.json')),
            hash_file('sha256', $theirs),
            'El manifiesto de archivos compartidos difiere entre los dos repos. Tiene que ser el mismo archivo.',
        );
    }
}
