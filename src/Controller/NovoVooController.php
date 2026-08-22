<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\Voo;
use App\Repository\AeronaveRepository;
use App\Repository\PilotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registro de voo: importar telemetria (dropzone simula leitura das
 * pastas do script de captura) ou registro manual.
 *
 * **Atualizado (backend real):** a frota do seletor de aeronave agora
 * vem de `App\Entity\Aeronave` (ver "Backend: mapa ao vivo e histórico
 * da frota") em vez de um array mock próprio.
 *
 * **Atualizado: registro manual grava de verdade.** `publicar()` abaixo
 * cria um `Voo` real (ver README, "Novo voo") quando o modo é
 * "Registro manual" — o modo "Importar telemetria" continua sendo uma
 * simulação no JS (nomes de arquivo fixos, sem leitura de CSV de
 * verdade) e seu botão "Publicar" continua mockado de propósito: dar
 * suporte real a ele precisa de upload de arquivo + parsing de CSV no
 * servidor reaproveitando `TelemetryDeriver`, e a via real de
 * telemetria hoje já é a ingestão ACARS ao vivo (ver
 * `Api\AcarsIngestaoController`). "Salvar rascunho" também continua
 * mockado — rascunho retomável precisaria de um status novo em `Voo`
 * (hoje só `valido`/`acidentado`) e uma tela pra listá-los, fora do
 * escopo desta fatia.
 */
class NovoVooController extends AbstractController
{
    /** Mesmo conjunto de `Api\AcarsIngestaoController::TIPOS_VALIDOS` — mantenha os dois em sincronia. */
    private const TIPOS_VALIDOS = ['Carga', 'Pesquisa', 'Pessoal', 'Reposicionamento'];

    /** Ocorrências que o registro manual aceita, com a mesma severidade que `TelemetryDeriver` usa pras equivalentes detectadas por telemetria (pouso duro/estol/overspeed = 'bad', quique = 'warn') — mantém a cor do selo consistente entre voos manuais e automáticos no Logbook. */
    private const OCORRENCIA_TAGS = [
        'Pouso duro' => 'bad',
        'Quique' => 'warn',
        'Overspeed' => 'bad',
        'Estol' => 'bad',
    ];

    #[Route('/novo-voo', name: 'app_novo_voo', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('novo_voo/index.html.twig', [
            'activeView' => 'logbook',
            'pilot' => $pilot,
            'aircraft' => array_map(
                fn (Aeronave $a) => $this->aircraftViewModel($a),
                $aeronaves->findAllOrderedByBaseAndReg()
            ),
        ]);
    }

    /**
     * Publica um voo do "Registro manual" — sem telemetria medida, os
     * campos que só a telemetria sabe (`dist`, `combustivelKg`,
     * `tempoSoloMin`/`tempoArMin`, `metar`) ficam zerados/nulos (ver
     * `AcarsIngestaoController::ingerir()` pro equivalente com
     * telemetria de verdade). `codigo` fica `null` — mesmo estado dos
     * voos históricos sem gravação — é isso que já faz `portal.js`
     * tratar a linha como "sem telemetria" (não clicável pro relatório)
     * e `Voo::hasTelemetria()` devolver `false`, sem precisar de uma
     * flag "verificado" nova.
     */
    #[Route('/novo-voo/publicar', name: 'app_novo_voo_publicar', methods: ['POST'])]
    public function publicar(Request $request, EntityManagerInterface $em, PilotRepository $pilots, AeronaveRepository $aeronaves): JsonResponse
    {
        $sessionPilot = $request->getSession()->get('pilot');
        if (null === $sessionPilot) {
            return $this->json(['errors' => ['Sessão expirada — faça login novamente.']], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || JSON_ERROR_NONE !== json_last_error()) {
            return $this->json(['errors' => ['Payload não é JSON válido.']], 400);
        }

        $callsignNum = trim((string) ($data['callsignNum'] ?? ''));
        $tipoOperacao = (string) ($data['tipoOperacao'] ?? '');
        $aeronaveReg = strtoupper(trim((string) ($data['aeronaveReg'] ?? '')));
        $origem = strtoupper(trim((string) ($data['origem'] ?? '')));
        $destino = strtoupper(trim((string) ($data['destino'] ?? '')));
        $dataVoo = (string) ($data['data'] ?? '');
        $hora = (string) ($data['hora'] ?? '');
        $duracao = trim((string) ($data['duracao'] ?? ''));
        $condicao = trim((string) ($data['condicao'] ?? ''));
        $ocorrenciasIn = is_array($data['ocorrencias'] ?? null) ? $data['ocorrencias'] : [];
        $dificuldade = is_numeric($data['dificuldade'] ?? null) ? (int) $data['dificuldade'] : -1;
        $objetivo = trim((string) ($data['objetivo'] ?? ''));
        $relato = trim((string) ($data['relato'] ?? ''));
        $simbrief = trim((string) ($data['simbrief'] ?? ''));
        $visibilidade = (string) ($data['visibilidade'] ?? 'publico');

        $errors = [];
        if ('' === $callsignNum || !preg_match('/^\d{1,3}$/', $callsignNum)) {
            $errors[] = 'Informe o número do callsign (1 a 3 dígitos).';
        }
        if (!in_array($tipoOperacao, self::TIPOS_VALIDOS, true)) {
            $errors[] = sprintf('"Tipo de operação" precisa ser um de: %s.', implode(', ', self::TIPOS_VALIDOS));
        }
        if ('' === $aeronaveReg) {
            $errors[] = 'Selecione a aeronave.';
        }
        if ('' === $origem || '' === $destino || strlen($origem) > 8 || strlen($destino) > 8) {
            $errors[] = 'Preencha origem e destino (até 8 caracteres cada).';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataVoo) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $errors[] = 'Data/hora inválidas.';
        }
        $tempoMin = null;
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $duracao, $m)) {
            $tempoMin = ((int) $m[1]) * 60 + (int) $m[2];
        }
        if (null === $tempoMin || $tempoMin < 1) {
            $errors[] = 'Duração inválida — use o formato H:MM (ex.: 1:20).';
        }
        if ($dificuldade < 0 || $dificuldade > 100) {
            $errors[] = 'Dificuldade estimada precisa estar entre 0 e 100.';
        }

        $aeronave = '' !== $aeronaveReg ? $aeronaves->findOneByReg($aeronaveReg) : null;
        if ('' !== $aeronaveReg && null === $aeronave) {
            $errors[] = sprintf('Aeronave "%s" não encontrada na frota.', $aeronaveReg);
        }

        $pilot = $pilots->findOneByCid($sessionPilot['cid']);
        if (null === $pilot) {
            $errors[] = 'Piloto da sessão não encontrado.';
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        try {
            $startedAt = new \DateTimeImmutable($dataVoo.'T'.$hora.':00', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->json(['errors' => ['Data/hora inválidas.']], 422);
        }

        $condTag = match ($condicao) {
            'Neve' => 'bad',
            'Chuva', 'Nevoeiro', 'Turbulência' => 'warn',
            default => 'ok',
        };

        $ocorrencias = [];
        foreach ($ocorrenciasIn as $label) {
            $label = is_string($label) ? $label : '';
            if (isset(self::OCORRENCIA_TAGS[$label])) {
                $ocorrencias[] = ['label' => $label, 'tag' => self::OCORRENCIA_TAGS[$label]];
            }
        }

        $callsign = 'KBT'.$callsignNum;

        $voo = new Voo($pilot, $callsign, $tipoOperacao, $origem, $destino, $aeronaveReg, $startedAt, $tempoMin, $dificuldade);
        $voo->setDados([
            'rota' => $origem.' → '.$destino,
            'modelo' => $aeronave->getTipo(),
            'cond' => '' !== $condicao ? $condicao : 'Claro',
            'condTag' => $condTag,
            'ocorrencias' => $ocorrencias,
            // Sem telemetria medida, estes quatro ficam zerados/nulos —
            // não há como estimar dist/combustível/tempo ar-solo a
            // partir só do que o piloto digitou (ver docblock acima).
            'dist' => 0,
            'combustivelKg' => 0,
            'carga' => 'Não informado (registro manual)',
            'tempoSoloMin' => 0,
            'tempoArMin' => 0,
            'metar' => null,
            'pilotReport' => '' !== $relato ? $relato : null,
            // Capturados no formulário, sem tela nenhuma que os exiba
            // ainda (mesmo tipo de lacuna que "carga" tinha na ingestão
            // ACARS) — guardados pra não perder o que o piloto digitou.
            'objetivo' => '' !== $objetivo ? $objetivo : null,
            'simbrief' => '' !== $simbrief ? $simbrief : null,
            'visibilidade' => in_array($visibilidade, ['publico', 'interno'], true) ? $visibilidade : 'publico',
        ]);

        $em->persist($voo);

        // Mesmo efeito colateral que a ingestão ACARS aplica ao pousar
        // (ver `ingerir()`) — sem isso a Frota/Mapa ao vivo não saberiam
        // que este voo aconteceu.
        $aeronave->setHoras($aeronave->getHoras() + (int) round($tempoMin / 60));
        $aeronave->setPosIcao($destino);
        $aeronave->setStatus('Disponível');
        $aeronave->setEmVooDesde(null);
        $aeronave->setUltimoPingEm(null);

        $em->flush();

        return $this->json(['vooId' => $voo->getId(), 'callsign' => $callsign], 201);
    }

    /**
     * @return array{reg: string, tipo: string, base: string, pos: string, status: string, dot: string}
     */
    private function aircraftViewModel(Aeronave $a): array
    {
        $dot = match ($a->getStatusTag()) {
            'warn' => 'var(--accent)',
            'bad' => 'var(--danger)',
            default => 'var(--ok)',
        };

        return [
            'reg' => $a->getReg(),
            'tipo' => $a->getTipo(),
            'base' => $a->getBase(),
            'pos' => $a->getPosIcao(),
            'status' => $a->getStatusEfetivo(),
            'dot' => $dot,
        ];
    }
}
