<?php

namespace App\Support;

use App\Http\Requests\StudentRequest;
use Illuminate\Support\Str;

/**
 * Lee CSV como los guarda Excel o Google Sheets: UTF-8 (con o sin BOM) o Windows-1252,
 * separado por coma, punto y coma o tabulador, y con encabezados con o sin acentos.
 */
final class CsvReader
{
    /**
     * @param  array<string, string>  $aliases  encabezado normalizado → clave interna
     * @param  array<string, string>  $required  clave interna → nombre a mostrar en el error
     * @param  list<array{line: int, message: string}>  $errors
     * @return list<array{line: int, data: array<string, string>}>
     */
    public static function read(string $path, array $aliases, array $required, int $maxRows, string $emptyMessage, array &$errors): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $headerLine = explode("\n", $content)[0] ?? '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($headerLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (! $header || count(array_filter($header, fn ($h) => trim((string) $h) !== '')) === 0) {
            $errors[] = ['line' => 1, 'message' => 'El archivo está vacío.'];

            return [];
        }

        $columns = array_map(fn ($h) => $aliases[self::normalizeHeader((string) $h)] ?? null, $header);
        $missing = array_diff(array_keys($required), $columns);
        if ($missing) {
            $errors[] = ['line' => 1, 'message' => 'Faltan columnas: '.implode(', ', array_map(fn ($m) => $required[$m], $missing)).'. Descarga la plantilla para ver el formato.'];

            return [];
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null] || count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $data = [];
            foreach ($columns as $i => $key) {
                if ($key !== null) {
                    $data[$key] = StudentRequest::clean((string) ($values[$i] ?? '')) ?? '';
                }
            }
            $rows[] = ['line' => $line, 'data' => $data];

            if (count($rows) > $maxRows) {
                $errors[] = ['line' => $line, 'message' => "El archivo tiene más de {$maxRows} filas. Divídelo en varios archivos."];

                return [];
            }
        }
        fclose($handle);

        if (! $rows) {
            $errors[] = ['line' => 2, 'message' => $emptyMessage];
        }

        return $rows;
    }

    public static function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($header)))), '_');
    }
}
