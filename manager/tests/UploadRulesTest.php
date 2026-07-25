<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/inc/lists.php';

use PHPUnit\Framework\TestCase;

/**
 * Cobre as decisoes puras extraidas de handle_upload() (CommonFunctions.php):
 * validacao de subdiretorio, resolucao de extensao por MIME e montagem do nome
 * final do arquivo. A guarda is_uploaded_file() torna handle_upload() em si
 * intestavel sem um upload HTTP real — ver plans/009.
 */
final class UploadRulesTest extends TestCase
{
    public function testSubdirValido(): void
    {
        $this->assertTrue(upload_valid_subdir('products'));
        $this->assertTrue(upload_valid_subdir('/products/'));
    }

    public function testSubdirRecusaVazioEDotDot(): void
    {
        $this->assertFalse(upload_valid_subdir(''));
        $this->assertFalse(upload_valid_subdir('/'));
        $this->assertFalse(upload_valid_subdir('../etc'));
        $this->assertFalse(upload_valid_subdir('a/../b'));
        $this->assertFalse(upload_valid_subdir('a\\b'));
    }

    public function testExtensaoVemDoMimeNaoDoNome(): void
    {
        $this->assertSame('jpg', upload_resolve_extension('image/jpeg', ['jpg', 'png']));
    }

    public function testMimeForaDoMapaRecusa(): void
    {
        // Garante que a allowlist nao e a unica defesa: mesmo com 'php' na
        // allowlist, um MIME que nao esta no mapa e recusado.
        $this->assertNull(upload_resolve_extension('application/x-php', ['jpg', 'php']));
    }

    public function testMimeConhecidoMasExtensaoNaoPermitidaRecusa(): void
    {
        $this->assertNull(upload_resolve_extension('application/pdf', ['jpg']));
    }

    public function testTodosOsMimesDoMapaResolvemQuandoPermitidos(): void
    {
        // Trava o mapa: adicionar/remover MIME sem intencao reprova este teste.
        $expected = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/avif'    => 'avif',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/csv'      => 'csv',
        ];

        foreach ($expected as $mime => $ext) {
            $this->assertSame(
                $ext,
                upload_resolve_extension($mime, [$ext]),
                "MIME '$mime' deveria resolver para '$ext'"
            );
        }
    }

    public function testNomeUsaSlugTimestampEExtensao(): void
    {
        $filename = upload_target_filename('Foto Bonita.JPEG', 'webp', '2026-07-24_10-00-00');
        $this->assertSame('foto-bonita_2026-07-24_10-00-00.webp', $filename);
    }

    public function testNomeVazioViraArquivo(): void
    {
        // pathinfo(".gitignore", PATHINFO_FILENAME) === '' (dotfile sem nome antes
        // do ponto), entao generate_slug('') tambem e '' e o fallback 'arquivo' entra.
        $filename = upload_target_filename('.gitignore', 'jpg', '2026-07-24_10-00-00');
        $this->assertStringStartsWith('arquivo_', $filename);
    }

    public function testNomeMuitoLongoEhTruncadoEm80(): void
    {
        $longName = str_repeat('a', 200) . '.png';
        $filename = upload_target_filename($longName, 'jpg', '2026-07-24_10-00-00');
        $base = explode('_2026-07-24_10-00-00.jpg', $filename)[0];
        $this->assertLessThanOrEqual(80, strlen($base));
        $this->assertSame(80, strlen($base));
    }

    public function testMesmoSegundoGeraMesmoNome(): void
    {
        // Documenta a limitacao conhecida: granularidade de 1s no timestamp faz
        // dois uploads do mesmo nome no mesmo segundo colidirem (2o sobrescreve
        // o 1o). Nao e um bug a corrigir neste plano.
        $first = upload_target_filename('same.jpg', 'jpg', '2026-07-24_10-00-00');
        $second = upload_target_filename('same.jpg', 'jpg', '2026-07-24_10-00-00');
        $this->assertSame($first, $second);
    }
}
