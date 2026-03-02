<?php

namespace App;

use function gc_disable;
use function fopen;
use function fclose;
use function fread;
use function fwrite;
use function feof;
use function strpos;
use function substr;
use function strlen;
use function ksort;
use function stream_set_read_buffer;
use function stream_set_write_buffer;

final class Parser
{
    public function parse(string $inputPath, string $outputPath): void
    {
        ini_set('memory_limit', -1);
        gc_disable();

        $handle = fopen($inputPath, 'rb');
        stream_set_read_buffer($handle, 64 * 1024);

        $bufferSize = 64 * 1024;
        $leftover = '';
        $rowList = [];

        while (!feof($handle)) {
            $chunk = fread($handle, $bufferSize);

            // 이전 루프에서 남은 데이터가 있다면 이번 청크 앞에 붙임
            if ($leftover !== '') {
                $chunk = "{$leftover}{$chunk}";
                $leftover = '';
            }

            $offset = 0;
            $chunkLen = strlen($chunk);

            while ($offset + 50 < $chunkLen) {
                $lineEnd = strpos($chunk, "\n", $offset + 50);

                if ($lineEnd === false) {
                    break; // 줄바꿈이 없으면 다음 fread를 위해 탈출
                }

                // 추출할 위치와 길이 계산
                $start = $offset + 25;
                $len = $lineEnd - $offset - 40;

                if ($len > 0) {
                    $key = substr($chunk, $start, $len);

                    if (isset($rowList[$key])) {
                        $rowList[$key]++;
                    } else {
                        $rowList[$key] = 1;
                    }
                }

                $offset = $lineEnd + 1;
            }

            // 남은 부분 보관 (결합 횟수 최소화)
            if ($offset < $chunkLen) {
                $leftover = substr($chunk, $offset);
            }
        }

        fclose($handle);

        // 정렬 및 출력을 위해 중첩 구조로 재구성
        $finalList = [];
        foreach ($rowList as $combinedKey => $count) {
            $date = substr($combinedKey, -10);
            $slug = substr($combinedKey, 0, -11);
            $finalList[$slug][$date] = $count;
        }
        unset($rowList);
        $rowList = $finalList;

        $out = fopen($outputPath, 'wb+');
        stream_set_write_buffer($out, 4 * 1024 * 1024);

        fwrite($out, "{\n");

        foreach ($rowList as $slug => $dates) {
            ksort($dates);

            $dateLines = [];
            foreach ($dates as $date => $count) {
                $dateLines[] = "        \"{$date}\": {$count}";
            }

            $output = "    \"\\/blog\\/{$slug}\": {\n"
                    . implode(",\n", $dateLines)
                    . "\n    },\n";

            fwrite($out, $output);
        }

        // 마지막 ",\n" (2바이트) 제거를 위해 포인터 이동
        fseek($out, -2, SEEK_CUR);
        fwrite($out, "\n}");
        fclose($out);
    }
}
