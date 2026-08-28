<?php

namespace App\Controller;

use App\Entity\TipoAeronave;
use App\Repository\AeronaveRepository;
use App\Repository\TipoAeronaveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro do perfil de performance por TIPO de aeronave — admin-only,
 * mesmo guard de `AeroportoController`/`SolicitacoesController`
 * (`$pilot['admin']` na sessão, ver `ensureAdmin()`): assim como
 * aeroportos, é dado de referência compartilhado (as calculadoras de
 * `/ferramentas` usam pra frota inteira), então um número errado afeta
 * todo mundo que voar aquele tipo, não só quem cadastrou.
 *
 * Ver `App\Entity\TipoAeronave` — **nenhum valor de performance é
 * pré-cadastrado**: esta tela só existe pra o admin digitar os números
 * reais (tirados do POH/AFM de cada tipo). O `<select>` de "Tipo" no
 * formulário (`nomesConhecidos()`) lista os tipos que já existem na
 * frota (`AeronaveRepository::findDistinctTipos()`) pra reduzir erro de
 * digitação — o casamento com `Aeronave::$tipo` é por valor exato de
 * string, sem FK (ver docblock da entidade) — mas também aceita "outro
 * tipo" digitado à mão, mesmo padrão de `NovaAeronaveController`.
 */
class TipoAeronaveController extends AbstractController
{
    #[Route('/tipos-aeronave', name: 'app_tipos_aeronave', methods: ['GET'])]
    public function index(Request $request, TipoAeronaveRepository $tipos, AeronaveRepository $aeronaves): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        return $this->render('tipo_aeronave/index.html.twig', [
            'activeView' => 'tiposAeronave',
            'pilot' => $pilot,
            'tipos' => array_map(fn (TipoAeronave $t) => $this->viewModel($t), $tipos->findAllOrderedByNome()),
            'tiposConhecidos' => $aeronaves->findDistinctTipos(),
        ]);
    }

    #[Route('/tipos-aeronave', name: 'app_tipos_aeronave_criar', methods: ['POST'])]
    public function criar(Request $request, EntityManagerInterface $em, TipoAeronaveRepository $tipos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $data = $this->decode($request);
        [$campos, $errors] = $this->validar($data);

        if ([] === $errors && $tipos->existsByNome($campos['nome'])) {
            $errors[] = sprintf('Já existe um perfil de performance cadastrado para "%s".', $campos['nome']);
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        $tipo = new TipoAeronave($campos['nome']);
        $this->aplicarCampos($tipo, $campos);

        $em->persist($tipo);
        $em->flush();

        return $this->json($this->viewModel($tipo), 201);
    }

    #[Route('/tipos-aeronave/{id}/atualizar', name: 'app_tipos_aeronave_atualizar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function atualizar(int $id, Request $request, EntityManagerInterface $em, TipoAeronaveRepository $tipos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $tipo = $tipos->find($id);
        if (null === $tipo) {
            return $this->json(['error' => 'Perfil de performance não encontrado.'], 404);
        }

        $data = $this->decode($request);
        [$campos, $errors] = $this->validar($data);

        if ([] === $errors) {
            $outro = $tipos->findOneByNome($campos['nome']);
            if (null !== $outro && $outro->getId() !== $tipo->getId()) {
                $errors[] = sprintf('Já existe um perfil de performance cadastrado para "%s".', $campos['nome']);
            }
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        $tipo->setNome($campos['nome']);
        $this->aplicarCampos($tipo, $campos);
        $tipo->touch();

        $em->flush();

        return $this->json($this->viewModel($tipo));
    }

    #[Route('/tipos-aeronave/{id}/remover', name: 'app_tipos_aeronave_remover', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remover(int $id, Request $request, EntityManagerInterface $em, TipoAeronaveRepository $tipos): JsonResponse
    {
        if (null !== $err = $this->ensureAdmin($request)) {
            return $err;
        }

        $tipo = $tipos->find($id);
        if (null === $tipo) {
            return $this->json(['error' => 'Perfil de performance não encontrado.'], 404);
        }

        $em->remove($tipo);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Mesmo guard de `AeroportoController::ensureAdmin()`.
     */
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }
        if (empty($pilot['admin'])) {
            return $this->json(['error' => 'Ação restrita a administradores.'], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Valida e normaliza os campos comuns a `criar()`/`atualizar()`. Os
     * campos numéricos são todos opcionais (podem ficar `null`) — só
     * `nome` é obrigatório; quando informado, um numérico precisa ser
     * um número não-negativo (peso/distância/consumo negativo não faz
     * sentido físico).
     *
     * @return array{0: array{nome: string, pesoVazioLb: ?int, pesoMaxDecolagemLb: ?int, combustivelMaxGal: ?int, consumoGph: ?float, decolagemDistanciaFt: ?int, pousoDistanciaFt: ?int, observacoes: ?string}, 1: list<string>}
     */
    private function validar(array $data): array
    {
        $nome = trim((string) ($data['nome'] ?? ''));
        $observacoes = trim((string) ($data['observacoes'] ?? ''));

        $errors = [];
        if ('' === $nome) {
            $errors[] = 'Informe o nome do tipo.';
        } elseif (mb_strlen($nome) > 60) {
            $errors[] = 'Nome do tipo muito longo (máximo 60 caracteres).';
        }

        $numericos = [
            'pesoVazioLb' => 'Peso vazio',
            'pesoMaxDecolagemLb' => 'Peso máximo de decolagem (MTOW)',
            'combustivelMaxGal' => 'Combustível máximo',
            'consumoGph' => 'Consumo médio',
            'decolagemDistanciaFt' => 'Distância de decolagem',
            'pousoDistanciaFt' => 'Distância de pouso',
        ];
        $valores = [];
        foreach ($numericos as $campo => $rotulo) {
            $bruto = $data[$campo] ?? null;
            if (null === $bruto || '' === $bruto) {
                $valores[$campo] = null;
                continue;
            }
            if (!is_numeric($bruto) || (float) $bruto < 0) {
                $errors[] = sprintf('%s inválido — informe um número maior ou igual a zero, ou deixe em branco.', $rotulo);
                $valores[$campo] = null;
                continue;
            }
            $valores[$campo] = 'consumoGph' === $campo ? (float) $bruto : (int) round((float) $bruto);
        }

        return [[
            'nome' => $nome,
            'pesoVazioLb' => $valores['pesoVazioLb'],
            'pesoMaxDecolagemLb' => $valores['pesoMaxDecolagemLb'],
            'combustivelMaxGal' => $valores['combustivelMaxGal'],
            'consumoGph' => $valores['consumoGph'],
            'decolagemDistanciaFt' => $valores['decolagemDistanciaFt'],
            'pousoDistanciaFt' => $valores['pousoDistanciaFt'],
            'observacoes' => '' === $observacoes ? null : $observacoes,
        ], $errors];
    }

    /**
     * @param array{pesoVazioLb: ?int, pesoMaxDecolagemLb: ?int, combustivelMaxGal: ?int, consumoGph: ?float, decolagemDistanciaFt: ?int, pousoDistanciaFt: ?int, observacoes: ?string} $campos
     */
    private function aplicarCampos(TipoAeronave $tipo, array $campos): void
    {
        $tipo->setPesoVazioLb($campos['pesoVazioLb']);
        $tipo->setPesoMaxDecolagemLb($campos['pesoMaxDecolagemLb']);
        $tipo->setCombustivelMaxGal($campos['combustivelMaxGal']);
        $tipo->setConsumoGph($campos['consumoGph']);
        $tipo->setDecolagemDistanciaFt($campos['decolagemDistanciaFt']);
        $tipo->setPousoDistanciaFt($campos['pousoDistanciaFt']);
        $tipo->setObservacoes($campos['observacoes']);
    }

    /**
     * @return array{id: int, nome: string, pesoVazioLb: ?int, pesoMaxDecolagemLb: ?int, combustivelMaxGal: ?int, consumoGph: ?float, decolagemDistanciaFt: ?int, pousoDistanciaFt: ?int, observacoes: ?string}
     */
    private function viewModel(TipoAeronave $t): array
    {
        return [
            'id' => $t->getId(),
            'nome' => $t->getNome(),
            'pesoVazioLb' => $t->getPesoVazioLb(),
            'pesoMaxDecolagemLb' => $t->getPesoMaxDecolagemLb(),
            'combustivelMaxGal' => $t->getCombustivelMaxGal(),
            'consumoGph' => $t->getConsumoGph(),
            'decolagemDistanciaFt' => $t->getDecolagemDistanciaFt(),
            'pousoDistanciaFt' => $t->getPousoDistanciaFt(),
            'observacoes' => $t->getObservacoes(),
        ];
    }
}
