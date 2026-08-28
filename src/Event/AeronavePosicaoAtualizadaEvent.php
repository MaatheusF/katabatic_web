<?php

namespace App\Event;

use App\Entity\Aeronave;
use App\Entity\PosicaoAoVivo;

/**
 * Disparado sempre que um ping de posição do ACARS é aceito e gravado
 * (ver `AcarsIngestaoController::posicao()`). Hoje não existe nenhum
 * listener — o Mapa ao vivo aprende da posição nova por polling (`GET
 * /mapa-ao-vivo/posicoes`), não por este evento.
 *
 * **Por que ele existe mesmo sem listener hoje:** é o encaixe pronto pra
 * quando fizer sentido empurrar a posição em vez de esperar o próximo
 * polling — um listener novo publicando no Mercure (é o que o contrato
 * completo em `docs/payload-telemetria-acars.md`, seção 5.3, já
 * pressupõe) ou num WebSocket, o dia que isso for necessário, se
 * registra sozinho (`#[AsEventListener]`) sem tocar em
 * `AcarsIngestaoController` nem no resto do fluxo de ingestão. Decisão
 * deliberada de não introduzir Mercure/WebSocket nesta fatia — mesma
 * lógica de "não normalizar sem volume real" do resto do projeto: com
 * 6 aeronaves, polling de alguns segundos já resolve, e o mapa não tem
 * infraestrutura de push nenhuma ainda (sem `symfony/mercure-bundle`).
 */
final class AeronavePosicaoAtualizadaEvent
{
    public function __construct(
        public readonly Aeronave $aeronave,
        public readonly PosicaoAoVivo $posicao,
    ) {
    }
}
