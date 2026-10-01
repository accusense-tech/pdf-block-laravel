<?php

declare(strict_types=1);

namespace PdfBlock\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executa os `*_check.php` como casos PHPUnit — um caso por arquivo.
 *
 * Por que um wrapper e não uma reescrita: os 24 checks já são bons (vários se
 * declaram "espelho" do teste TS correspondente e travam paridade cross-linguagem)
 * e JÁ devolvem exit code correto. O que faltava não era a asserção — era alguém
 * EXECUTANDO: não havia phpunit.xml, não havia `composer test`, e
 * `packages/laravel` estava fora do `paths` do CI. Reescrevê-los à mão seria trocar
 * risco de conversão por nenhum ganho de sinal.
 *
 * Cada check roda num processo próprio: eles são scripts de topo de arquivo, com
 * `$failures` global e `exit()` — carregá-los no mesmo processo derrubaria a suíte
 * inteira no primeiro `exit`.
 *
 * Um check novo é descoberto pelo nome (`*_check.php`), sem registro em lugar
 * nenhum. `style_css_dump.php` fica de fora de propósito: é um dump que lê stdin
 * (alimenta o golden de paridade), não um check.
 */
final class ChecksTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function checkFiles(): array
    {
        $files = glob(__DIR__ . '/*_check.php') ?: [];
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('checkFiles')]
    public function test_check_passa(string $file): void
    {
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($file) . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $text = implode("\n", $output);

        $this->assertSame(
            0,
            $exit,
            "O check " . basename($file) . " falhou (exit {$exit}).\n\n"
                . "--- saída ---\n{$text}\n"
        );

        // Rede de segurança: um check que perdesse o `exit(1)` do rodapé passaria
        // por vacuidade. A linha "FAIL:" é a convenção compartilhada por todos eles.
        $this->assertStringNotContainsString(
            'FAIL:',
            $text,
            "O check " . basename($file) . " terminou com exit 0 mas imprimiu FAIL — "
                . "provavelmente perdeu o `exit(\$failures === 0 ? 0 : 1)` do rodapé.\n\n{$text}"
        );
    }

    public function test_a_descoberta_de_checks_nao_esta_vazia(): void
    {
        $this->assertGreaterThan(
            20,
            count(self::checkFiles()),
            'Nenhum (ou quase nenhum) check descoberto — o glob provavelmente quebrou.'
        );
    }
}
