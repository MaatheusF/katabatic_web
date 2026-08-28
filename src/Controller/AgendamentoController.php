<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Entity\Agendamento;
use App\Repository\AeronaveRepository;
use App\Repository\AgendamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agendamento de voo: reservar uma aeronave da frota pra um voo que ainda
 * vai acontecer (diferente de "Novo voo", que registra um voo que já
 * aconteceu). A ideia central desta tela é permitir agendar *mais de uma*
 * perna seguida pra mesma aeronave — se a próxima perna começa onde a
 * anterior termina, elas formam uma sequência (agendamento.js desenha isso
 * como uma linha do tempo por aeronave, não como uma tabela solta).
 *
 * **Atualizado (backend real, ver README "Agendamento de voo"):** era tudo
 * mock (array em `agendamentos()`/`fleet()`, manipulado só em memória por
 * `agendamento.js`, sem persistir entre reloads) — agora `App\Entity\
 * Agendamento` é tabela de verdade e a frota vem de `App\Entity\Aeronave`
 * (mesma fonte que Mapa ao vivo/Portal/Novo voo já usam). Criar/editar/
 * remover são POSTs de verdade (`agendamento.js` faz `fetch`, mesmo padrão
 * de Solicitações), sem reload.
 *
 * **Duas validações, dois lugares.** `agendamento.js` já checa sobreposição
 * de horário e sugere/avisa sobre a sequência de posição pra dar feedback
 * imediato sem round-trip — mas quem decide de verdade é o servidor:
 * `AgendamentoRepository::hasOverlap()` roda de novo em cada POST de criar/
 * editar, porque duas abas/pilotos diferentes validando cada um contra o
 * próprio snapshot em memória não impede os dois POSTs criarem uma
 * sobreposição real no banco (ver docblock do repositório). O aviso de
 * sequência (perna não continua de onde a anterior terminou) continua só
 * no cliente — é informativo, nunca bloqueou nada, nem no mock.
 *
 * Sem restrição de "dono": qualquer piloto logado cria/edita/remove
 * qualquer agendamento (é uma agenda operacional compartilhada, não uma
 * lista pessoal) — mesmo guard simples de sessão que o resto do app usa,
 * sem o papel "admin" que Solicitações exige.
 *
 * **Ligação com o ACARS ainda não existe** (promover um agendamento em
 * aberto pra "em andamento"/"concluído" quando o feed real bater com ele) —
 * ver README, é trabalho de uma fatia futura.
 *
 * **Atualizado: origem/destino viraram busca, não mais um `<select>`.**
 * Desde a importação global de aeroportos (ver README, "Backend:
 * importação global de aeroportos") o catálogo tem milhares de linhas —
 * um `<select>` com uma `<option>` por aeroporto deixou de ser viável.
 * `aeroportosBuscaUrl` aponta pra `AeroportoController::buscar()`;
 * `agendamento.js` busca sob demanda (ICAO ou cidade) num combobox em
 * vez de carregar o catálogo inteiro de cara — ver `templates/
 * agendamento/index.html.twig` e o JS da tela.
 */
class AgendamentoController extends AbstractController
{
    /** Mesmos valores de `Voo::$tipoOperacao` / chips do formulário (ver templates/agendamento/index.html.twig). */
    private const TIPOS_VALIDOS = ['Carga', 'Pesquisa', 'Pessoal', 'Reposicionamento'];

    #[Route('/agendamentos', name: 'app_agendamentos', methods: ['GET'])]
    public function index(Request $request, AeronaveRepository $aeronaves, AgendamentoRepository $agendamentos): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('agendamento/index.html.twig', [
            'activeView' => 'agendamentos',
            'pilot' => $pilot,
            'fleet' => array_map(
                fn (Aeronave $a) => $this->fleetViewModel($a),
                $aeronaves->findAllOrderedByBaseAndReg()
            ),
            'agendamentos' => array_map(
                fn (Agendamento $a) => $this->agendamentoViewModel($a),
                $agendamentos->findAllOrderedByWindow()
            ),
            'aeroportosBuscaUrl' => $this->generateUrl('app_aeroportos_buscar'),
            'nowIso' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/agendamentos', name: 'app_agendamentos_criar', methods: ['POST'])]
    public function criar(Request $request, EntityManagerInterface $em, AeronaveRepository $aeronaves, AgendamentoRepository $agendamentos): JsonResponse
    {
        if (null !== $err = $this->ensureLogado($request)) {
            return $err;
        }

        $data = json_decode($request->getContent(), true);
        $v = $this->validar(is_array($data) ? $data : [], $aeronaves, $agendamentos, null);
        if ([] !== $v['errors']) {
            return $this->json(['errors' => $v['errors']], 422);
        }

        $agendamento = new Agendamento(
            $v['aeronave'],
            $v['origem'],
            $v['destino'],
            $v['tipo'],
            $v['piloto'],
            $v['de'],
            $v['ate'],
            $v['notas'],
        );
        $em->persist($agendamento);
        $em->flush();

        return $this->json(['agendamento' => $this->agendamentoViewModel($agendamento)], 201);
    }

    #[Route('/agendamentos/{id}/atualizar', name: 'app_agendamentos_atualizar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function atualizar(int $id, Request $request, EntityManagerInterface $em, AeronaveRepository $aeronaves, AgendamentoRepository $agendamentos): JsonResponse
    {
        if (null !== $err = $this->ensureLogado($request)) {
            return $err;
        }

        $agendamento = $agendamentos->find($id);
        if (null === $agendamento) {
            return $this->json(['error' => 'Agendamento não encontrado.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        // `$id` (não `null`) como excludeId — a checagem de sobreposição
        // não pode contar o próprio agendamento sendo editado como se
        // fosse outro (ver docblock de `validar()`/`hasOverlap()`).
        $v = $this->validar(is_array($data) ? $data : [], $aeronaves, $agendamentos, $id);
        if ([] !== $v['errors']) {
            return $this->json(['errors' => $v['errors']], 422);
        }

        $agendamento->setAeronave($v['aeronave']);
        $agendamento->setOrigem($v['origem']);
        $agendamento->setDestino($v['destino']);
        $agendamento->setTipoOperacao($v['tipo']);
        $agendamento->setPiloto($v['piloto']);
        $agendamento->setDe($v['de']);
        $agendamento->setAte($v['ate']);
        $agendamento->setNotas($v['notas']);
        $em->flush();

        return $this->json(['agendamento' => $this->agendamentoViewModel($agendamento)]);
    }

    #[Route('/agendamentos/{id}/remover', name: 'app_agendamentos_remover', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remover(int $id, Request $request, EntityManagerInterface $em, AgendamentoRepository $agendamentos): JsonResponse
    {
        if (null !== $err = $this->ensureLogado($request)) {
            return $err;
        }

        $agendamento = $agendamentos->find($id);
        if (null === $agendamento) {
            return $this->json(['error' => 'Agendamento não encontrado.'], 404);
        }

        $em->remove($agendamento);
        $em->flush();

        return $this->json(['id' => $id]);
    }

    /**
     * Mesmo guard de sessão de `index()`, mas devolvendo JSON em vez de
     * redirecionar — `criar()`/`atualizar()`/`remover()` são chamadas via
     * `fetch` por `agendamento.js`, não navegação de página. Sem checagem
     * de papel "admin" (ver docblock da classe).
     */
    private function ensureLogado(Request $request): ?JsonResponse
    {
        if (null === $request->getSession()->get('pilot')) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        return null;
    }

    /**
     * Valida o corpo comum a `criar()`/`atualizar()` e resolve a
     * `Aeronave` pelo `reg` informado — devolve ou os valores prontos
     * pra persistir (`errors` vazio) ou a lista de erros (os outros
     * campos vêm `null`/vazios nesse caso, nunca use-os sem checar
     * `errors` primeiro). `excludeId` é o id do próprio agendamento
     * quando é uma edição (ver `atualizar()`), `null` quando é criação.
     *
     * @param array<string, mixed> $data
     *
     * @return array{errors: list<string>, aeronave: ?Aeronave, origem: string, destino: string, tipo: string, piloto: string, de: ?\DateTimeImmutable, ate: ?\DateTimeImmutable, notas: ?string}
     */
    private function validar(array $data, AeronaveRepository $aeronaves, AgendamentoRepository $agendamentos, ?int $excludeId): array
    {
        $errors = [];

        $reg = strtoupper(trim((string) ($data['reg'] ?? '')));
        $origem = strtoupper(trim((string) ($data['origem'] ?? '')));
        $destino = strtoupper(trim((string) ($data['destino'] ?? '')));
        $tipo = (string) ($data['tipo'] ?? '');
        $piloto = trim((string) ($data['piloto'] ?? ''));
        $notas = trim((string) ($data['notas'] ?? ''));
        $notas = '' !== $notas ? $notas : null;

        if ('' === $reg) {
            $errors[] = 'Faltou "reg".';
        }
        if ('' === $origem) {
            $errors[] = 'Faltou "origem".';
        }
        if ('' === $destino) {
            $errors[] = 'Faltou "destino".';
        }
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $errors[] = sprintf('"tipo" precisa ser um de: %s.', implode(', ', self::TIPOS_VALIDOS));
        }
        if ('' === $piloto) {
            $errors[] = 'Faltou "piloto".';
        }

        $de = $ate = null;
        $deRaw = (string) ($data['de'] ?? '');
        $ateRaw = (string) ($data['ate'] ?? '');
        if ('' === $deRaw || '' === $ateRaw) {
            $errors[] = 'Faltou "de" ou "ate".';
        } else {
            try {
                $de = new \DateTimeImmutable($deRaw);
                $ate = new \DateTimeImmutable($ateRaw);
            } catch (\Throwable $e) {
                $errors[] = '"de"/"ate" inválidos: '.$e->getMessage();
            }
            if (null !== $de && null !== $ate && $de >= $ate) {
                $errors[] = '"ate" precisa ser depois de "de".';
            }
        }

        $aeronave = null;
        if ('' !== $reg) {
            $aeronave = $aeronaves->findOneByReg($reg);
            if (null === $aeronave) {
                $errors[] = sprintf('Aeronave "%s" não cadastrada na frota.', $reg);
            }
        }

        // Só checa sobreposição depois de tudo o mais já ter passado —
        // sem aeronave/janela válidas, a consulta nem faz sentido.
        if ([] === $errors && null !== $aeronave && null !== $de && null !== $ate
            && $agendamentos->hasOverlap($aeronave, $de, $ate, $excludeId)) {
            $errors[] = 'Essa aeronave já tem outro voo agendado que se sobrepõe a essa janela de horário.';
        }

        return [
            'errors' => $errors,
            'aeronave' => $aeronave,
            'origem' => $origem,
            'destino' => $destino,
            'tipo' => $tipo,
            'piloto' => $piloto,
            'de' => $de,
            'ate' => $ate,
            'notas' => $notas,
        ];
    }

    /**
     * @return array{reg: string, tipo: string, base: string, pos: string, status: string, statusTag: string}
     */
    private function fleetViewModel(Aeronave $a): array
    {
        return [
            'reg' => $a->getReg(),
            'tipo' => $a->getTipo(),
            'base' => $a->getBase(),
            'pos' => $a->getPosIcao(),
            'status' => $a->getStatusEfetivo(),
            'statusTag' => $a->getStatusTag(),
        ];
    }

    /**
     * @return array{id: int, reg: string, origem: string, destino: string, tipo: string, piloto: string, de: string, ate: string, notas: ?string}
     */
    private function agendamentoViewModel(Agendamento $a): array
    {
        return [
            'id' => $a->getId(),
            'reg' => $a->getAeronave()->getReg(),
            'origem' => $a->getOrigem(),
            'destino' => $a->getDestino(),
            'tipo' => $a->getTipoOperacao(),
            'piloto' => $a->getPiloto(),
            'de' => $a->getDe()->format(\DateTimeInterface::ATOM),
            'ate' => $a->getAte()->format(\DateTimeInterface::ATOM),
            'notas' => $a->getNotas(),
        ];
    }
}
