<?php

namespace App\Controller;

use App\Entity\Aeronave;
use App\Repository\AeronaveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro de aeronave: identificação (país/matrícula/tipo/base),
 * limites operacionais usados no índice de dificuldade e observações
 * internas. **Atualizado (backend real):** "Salvar aeronave" agora é
 * um POST de verdade (`nova-aeronave.js` faz um `fetch` em JSON) que
 * grava em `App\Entity\Aeronave` — ver "Backend: mapa ao vivo e
 * histórico da frota" no README.
 *
 * Fotos continuam só maquete visual (os slots nunca tiveram um
 * `<input type="file">` por trás, mesmo no mockup original) — fora do
 * escopo desta fatia, ver README "Lacunas conhecidas".
 */
class NovaAeronaveController extends AbstractController
{
    private const PREFIXOS = ['CL' => 'CC-', 'US' => 'N'];
    private const BASES_VALIDAS = ['PAFA', 'SCCI'];

    #[Route('/nova-aeronave', name: 'app_nova_aeronave', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('nova_aeronave/index.html.twig', [
            'activeView' => 'frota',
            'pilot' => $pilot,
        ]);
    }

    #[Route('/nova-aeronave', name: 'app_nova_aeronave_submit', methods: ['POST'])]
    public function submit(Request $request, EntityManagerInterface $em, AeronaveRepository $aeronaves): JsonResponse
    {
        if (null === $request->getSession()->get('pilot')) {
            return $this->json(['error' => 'Sessão expirada — faça login de novo.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $pais = (string) ($data['pais'] ?? '');
        $regDigitado = strtoupper(trim((string) ($data['reg'] ?? '')));
        $regDigitado = preg_replace('/[^A-Z0-9]/', '', $regDigitado) ?? '';
        $tipo = trim((string) ($data['tipo'] ?? ''));
        $base = (string) ($data['base'] ?? '');
        $limiteG = $data['limiteG'] ?? null;
        $vsLimiteFpm = $data['vsLimiteFpm'] ?? null;
        $horas = $data['horas'] ?? null;
        $observacoes = trim((string) ($data['observacoes'] ?? ''));

        $errors = [];
        if (!isset(self::PREFIXOS[$pais])) {
            $errors[] = 'País de registro inválido.';
        }
        if ('' === $regDigitado) {
            $errors[] = 'Informe a matrícula.';
        }
        if ('' === $tipo) {
            $errors[] = 'Informe o tipo da aeronave.';
        }
        if (!in_array($base, self::BASES_VALIDAS, true)) {
            $errors[] = 'Selecione uma base válida.';
        }

        $reg = null;
        if ([] === $errors) {
            $reg = self::PREFIXOS[$pais].$regDigitado;
            if (null !== $aeronaves->findOneByReg($reg)) {
                $errors[] = sprintf('Já existe uma aeronave cadastrada com a matrícula %s.', $reg);
            }
        }

        if ([] !== $errors) {
            return $this->json(['errors' => $errors], 422);
        }

        $aeronave = new Aeronave($reg, $pais, $tipo, $base);
        if (null !== $limiteG && is_numeric($limiteG)) {
            $aeronave->setLimiteG((float) $limiteG);
        }
        if (null !== $vsLimiteFpm && is_numeric($vsLimiteFpm)) {
            $aeronave->setVsLimiteFpm((int) $vsLimiteFpm);
        }
        if (null !== $horas && is_numeric($horas)) {
            $aeronave->setHoras(max(0, (int) $horas));
        }
        if ('' !== $observacoes) {
            $aeronave->setObservacoes($observacoes);
        }

        $em->persist($aeronave);
        $em->flush();

        return $this->json(['reg' => $aeronave->getReg()], 201);
    }
}
