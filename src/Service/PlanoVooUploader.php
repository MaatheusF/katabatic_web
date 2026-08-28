<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Processa e salva em disco o PDF do plano de voo (OFP do SimBrief,
 * tipicamente) anexado a um relatório de voo — ver
 * `VooController::adicionarPlanoVoo()`, `App\Entity\Voo::getPlanoVooPdf()`/
 * `setPlanoVooPdf()`.
 *
 * Mesma pasta por voo que `FotoVooUploader` usa
 * (`public/uploads/voos/{codigo}/`) — de propósito: é por isso que
 * `VooController::excluir()` (hard-delete) já limpa este arquivo de
 * graça junto com as fotos, sem precisar de nenhuma chamada extra —
 * `FotoVooUploader::removerPasta()` apaga a pasta inteira, PDF
 * incluído (ver `VooController::arquivosVooDir()`).
 *
 * Sem reencode (diferente de `FotoVooUploader`) — um PDF não tem EXIF
 * pra vazar nem faz sentido reprocessar; só valida que o arquivo é
 * mesmo um PDF de verdade (lê os primeiros bytes, não confia só no
 * Content-Type que o navegador declarou — mesmo espírito do
 * `getimagesize()` em `FotoVooUploader::processarComGd()`) e move pra
 * um nome opaco gerado com `random_bytes()`, mesmo padrão de nomes das
 * fotos. O nome original enviado pelo piloto é guardado à parte (ver
 * `App\Entity\Voo::getPlanoVooPdf()`) só pra exibição — nunca usado
 * como nome de arquivo em disco.
 *
 * Um só PDF por voo (não é galeria, como as fotos): anexar um novo
 * substitui o anterior — quem apaga o arquivo velho é
 * `VooController::adicionarPlanoVoo()`, não esta classe.
 */
final class PlanoVooUploader
{
    /** Tamanho máximo aceito, em bytes — um OFP com mapas/METAR embutidos raramente passa disso. */
    private const MAX_SIZE = 15 * 1024 * 1024;

    /** Tamanho do nome mostrado na UI — só corte defensivo, não tem relação com limite de sistema de arquivos. */
    private const MAX_NOME_EXIBIDO = 120;

    /**
     * @return array{arquivo: ?string, nomeOriginal: ?string, erro: ?string}
     */
    public function processar(UploadedFile $file, string $destDir): array
    {
        if (!$file->isValid()) {
            return ['arquivo' => null, 'nomeOriginal' => null, 'erro' => 'Não foi possível ler o arquivo enviado — tente de novo.'];
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return ['arquivo' => null, 'nomeOriginal' => null, 'erro' => 'Arquivo maior que 15 MB.'];
        }

        // Não confia no Content-Type declarado pelo navegador nem na
        // extensão do nome original - lê a assinatura de verdade do
        // arquivo (todo PDF começa com "%PDF-"), mesmo espírito do
        // getimagesize() em FotoVooUploader::processarComGd().
        $assinatura = @file_get_contents($file->getPathname(), false, null, 0, 5);
        if ('%PDF-' !== $assinatura) {
            return ['arquivo' => null, 'nomeOriginal' => null, 'erro' => 'Arquivo não é um PDF válido.'];
        }

        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['arquivo' => null, 'nomeOriginal' => null, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
        }

        $arquivo = bin2hex(random_bytes(4)).'.pdf';

        try {
            $file->move($destDir, $arquivo);
        } catch (FileException) {
            return ['arquivo' => null, 'nomeOriginal' => null, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
        }

        return ['arquivo' => $arquivo, 'nomeOriginal' => $this->sanitizarNome($file->getClientOriginalName()), 'erro' => null];
    }

    /**
     * Nome original só pra exibição (nunca usado como caminho em
     * disco — quem grava em disco é sempre o nome opaco gerado acima)
     * — corta pra um tamanho razoável e tira caracteres de controle,
     * pra não deixar a UI quebrar com um nome de arquivo absurdo.
     */
    private function sanitizarNome(?string $nome): ?string
    {
        if (null === $nome || '' === trim($nome)) {
            return null;
        }

        $limpo = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $nome) ?? $nome);

        return '' !== $limpo ? mb_substr($limpo, 0, self::MAX_NOME_EXIBIDO) : null;
    }

    /**
     * Apaga o PDF do disco — best-effort (nunca lança), mesmo espírito
     * de `FotoVooUploader::remover()`.
     */
    public function remover(string $destDir, string $arquivo): void
    {
        $caminho = $destDir.\DIRECTORY_SEPARATOR.$arquivo;
        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }
}
