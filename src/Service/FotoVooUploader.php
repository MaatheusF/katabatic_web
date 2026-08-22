<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Processa e salva em disco uma foto anexada a um relatório de voo
 * (ver `VooController::adicionarFotos()`, `App\Entity\Voo::getFotos()`/
 * `setFotos()`) — sem dependência nova no composer, só GD (que já vem
 * habilitada na maioria das instalações de PHP; ver `gdDisponivel()` e
 * o fallback abaixo pra quando não vem).
 *
 * Segue o mesmo padrão de `PerfilController::storePhoto()` (validação
 * de MIME/tamanho, nome novo gerado com `random_bytes()`, guardado
 * dentro de `public/uploads/`) — a diferença é que aqui, quando GD
 * está disponível, o arquivo não é só movido: é reaberto e regravado.
 * Duas coisas deliberadas nisso, nenhuma óbvia à primeira vista:
 *
 * 1. **Reencoda tudo pra JPEG**, descartando o EXIF original — efeito
 *    colateral querido, não um bug. Diferente da foto de perfil (só o
 *    próprio piloto vê o próprio avatar), a galeria de um voo é
 *    visível pra quem acessa aquele relatório, e uma foto "de
 *    verdade" (não print do simulador) anexada por um piloto pode
 *    trazer coordenadas de GPS no EXIF de origem — sem reencodar,
 *    isso vazaria de onde a foto foi tirada.
 * 2. **Lê a orientação EXIF antes de descartar o resto** e gira a
 *    imagem de acordo — sem isso, fotos tiradas em retrato no celular
 *    saem deitadas depois do reencode, porque a maioria das câmeras
 *    grava sempre em paisagem e só marca a orientação de exibição no
 *    próprio EXIF que o passo 1 acima joga fora.
 *
 * Quando a extensão GD não está disponível, cai pro mesmo caminho
 * simples de `storePhoto()` (move direto, sem reencode) — a galeria
 * continua funcionando, só sem a limpeza de EXIF/rotação automática
 * nesse caso (ver README, "Fotos do voo").
 */
final class FotoVooUploader
{
    /** Tipos MIME aceitos — mesma lista de `PerfilController::ALLOWED_PHOTO_MIME_TYPES`. */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Tamanho máximo aceito por foto, em bytes (8 MB — antes do reencode). */
    private const MAX_SIZE = 8 * 1024 * 1024;

    /** Lado maior, em px, depois do reencode — foto de celular moderno facilmente vem em 4000px+, o que não faz sentido pra uma galeria de relatório e só infla o disco. */
    private const MAX_DIM = 2000;

    private const JPEG_QUALITY = 85;

    public function gdDisponivel(): bool
    {
        return \function_exists('imagecreatetruecolor') && \function_exists('imagejpeg');
    }

    /**
     * @return array{arquivo: ?string, erro: ?string}
     */
    public function processar(UploadedFile $file, string $destDir): array
    {
        if (!$file->isValid()) {
            return ['arquivo' => null, 'erro' => 'Não foi possível ler o arquivo enviado — tente de novo.'];
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return ['arquivo' => null, 'erro' => 'Formato não suportado — envie um arquivo JPG, PNG ou WEBP.'];
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return ['arquivo' => null, 'erro' => 'Arquivo maior que 8 MB.'];
        }

        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['arquivo' => null, 'erro' => 'Não foi possível salvar a foto no servidor.'];
        }

        return $this->gdDisponivel()
            ? $this->processarComGd($file, $destDir)
            : $this->moverSemReencode($file, $destDir);
    }

    /**
     * @return array{arquivo: ?string, erro: ?string}
     */
    private function processarComGd(UploadedFile $file, string $destDir): array
    {
        // Não confia no Content-Type declarado pelo navegador nem na
        // extensão do nome original - getimagesize() abre o arquivo de
        // verdade e olha os bytes, é o que decide se é uma imagem
        // válida e de que tipo.
        $info = @getimagesize($file->getPathname());
        if (false === $info) {
            return ['arquivo' => null, 'erro' => 'Arquivo não é uma imagem válida.'];
        }
        $tipo = $info[2];

        $imagem = match ($tipo) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getPathname()),
            \IMAGETYPE_PNG => @imagecreatefrompng($file->getPathname()),
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getPathname()) : false,
            default => false,
        };
        if (false === $imagem) {
            return ['arquivo' => null, 'erro' => 'Não foi possível processar esta imagem — tente outro arquivo.'];
        }

        $imagem = $this->aplicarOrientacaoExif($imagem, $file->getPathname(), $tipo);
        $imagem = $this->redimensionarSePreciso($imagem);
        $imagem = $this->comFundoBranco($imagem);

        $arquivo = bin2hex(random_bytes(4)).'.jpg';
        $ok = imagejpeg($imagem, $destDir.\DIRECTORY_SEPARATOR.$arquivo, self::JPEG_QUALITY);
        imagedestroy($imagem);

        if (!$ok) {
            return ['arquivo' => null, 'erro' => 'Não foi possível salvar a foto no servidor.'];
        }

        return ['arquivo' => $arquivo, 'erro' => null];
    }

    /**
     * Fallback pra quando a extensão GD não está disponível — mesmo
     * caminho de `PerfilController::storePhoto()` (move direto, sem
     * reencode, sem limpeza de EXIF).
     *
     * @return array{arquivo: ?string, erro: ?string}
     */
    private function moverSemReencode(UploadedFile $file, string $destDir): array
    {
        $extensao = $file->guessExtension() ?? 'jpg';
        $arquivo = bin2hex(random_bytes(4)).'.'.$extensao;

        try {
            $file->move($destDir, $arquivo);
        } catch (FileException) {
            return ['arquivo' => null, 'erro' => 'Não foi possível salvar a foto no servidor.'];
        }

        return ['arquivo' => $arquivo, 'erro' => null];
    }

    /**
     * @return \GdImage
     */
    private function redimensionarSePreciso(\GdImage $imagem): \GdImage
    {
        $largura = imagesx($imagem);
        $altura = imagesy($imagem);
        $maior = max($largura, $altura);
        if ($maior <= self::MAX_DIM) {
            return $imagem;
        }

        $fator = self::MAX_DIM / $maior;
        $novaLargura = max(1, (int) round($largura * $fator));
        $novaAltura = max(1, (int) round($altura * $fator));
        $redim = imagecreatetruecolor($novaLargura, $novaAltura);
        imagecopyresampled($redim, $imagem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
        imagedestroy($imagem);

        return $redim;
    }

    /**
     * JPEG não tem canal alfa — sem preencher o fundo antes de gravar,
     * a área transparente de um PNG/WEBP de origem vira preto.
     *
     * @return \GdImage
     */
    private function comFundoBranco(\GdImage $imagem): \GdImage
    {
        $largura = imagesx($imagem);
        $altura = imagesy($imagem);
        $final = imagecreatetruecolor($largura, $altura);
        $branco = imagecolorallocate($final, 255, 255, 255);
        imagefill($final, 0, 0, $branco);
        imagecopy($final, $imagem, 0, 0, 0, 0, $largura, $altura);
        imagedestroy($imagem);

        return $final;
    }

    /**
     * Gira a imagem conforme a tag EXIF `Orientation` (só existe em
     * JPEG) antes do resto do processamento descartar o EXIF de
     * propósito (ver docblock da classe) — sem isso, foto tirada em
     * retrato no celular sai deitada. Best-effort: sem `ext-exif`
     * (extensão separada da GD, checada aqui de propósito) ou sem tag
     * de orientação, devolve a imagem como veio, sem girar.
     *
     * @return \GdImage
     */
    private function aplicarOrientacaoExif(\GdImage $imagem, string $caminho, int $tipo): \GdImage
    {
        if (\IMAGETYPE_JPEG !== $tipo || !\function_exists('exif_read_data')) {
            return $imagem;
        }

        $exif = @exif_read_data($caminho);
        $orientacao = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        $girada = match ($orientacao) {
            3 => imagerotate($imagem, 180, 0),
            6 => imagerotate($imagem, -90, 0),
            8 => imagerotate($imagem, 90, 0),
            default => false,
        };

        if (false === $girada) {
            return $imagem;
        }

        imagedestroy($imagem);

        return $girada;
    }

    /**
     * Apaga o arquivo de uma foto do disco — best-effort (nunca
     * lança): melhor a referência sumir do relatório mesmo que o
     * arquivo em disco não tenha sido removido por algum motivo (ex.:
     * permissão) do que travar a exclusão da referência por causa
     * disso.
     */
    public function remover(string $destDir, string $arquivo): void
    {
        $caminho = $destDir.\DIRECTORY_SEPARATOR.$arquivo;
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    /**
     * Apaga a pasta inteira de fotos de um voo — usado por
     * `VooController::excluir()` (hard-delete) pra não deixar
     * arquivos órfãos em disco quando o voo em si some do banco.
     * Best-effort, mesmo espírito de `remover()`.
     */
    public function removerPasta(string $destDir): void
    {
        if (!is_dir($destDir)) {
            return;
        }
        foreach (scandir($destDir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            @unlink($destDir.\DIRECTORY_SEPARATOR.$item);
        }
        @rmdir($destDir);
    }
}
