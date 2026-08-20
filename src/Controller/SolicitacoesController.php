<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Grid de administração: solicitações de adesão pendentes/aprovadas/
 * rejeitadas, e a lista de pilotos já cadastrados. Restrito a pilotos
 * com `admin` true na sessão mock (ver LoginController) - o rail só
 * mostra o link pra quem tem o papel, e o controller confere de novo
 * aqui (defesa em profundidade), redirecionando pro Portal quem tentar
 * acessar a URL direto sem ser admin.
 *
 * Aprovar/Rejeitar um pedido e mock: solicitacoes.js muda o status só
 * no proprio carregamento da pagina (sem POST), e "aprovar" injeta uma
 * linha nova na tabela de Pilotos so nessa sessao do navegador - nao
 * persiste num reload. Isso tambem significa que o que chega pelo
 * formulario publico (/adesao) nao aparece aqui: os dois lados usam
 * conjuntos mock independentes ate o schema do banco existir (ver
 * AdesaoController e README).
 */
class SolicitacoesController extends AbstractController
{
    #[Route('/solicitacoes', name: 'app_solicitacoes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pilot = $request->getSession()->get('pilot');
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }
        if (empty($pilot['admin'])) {
            return $this->redirectToRoute('app_portal');
        }

        return $this->render('solicitacoes/index.html.twig', [
            'activeView' => $request->query->get('view', 'solicitacoes'),
            'pilot' => $pilot,
            'solicitacoes' => $this->solicitacoes(),
            'pilotos' => $this->pilotos(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function solicitacoes(): array
    {
        return [
            ['id' => 1, 'nome' => 'Renata Alves', 'email' => 'renata.alves@example.com', 'discord' => 'renata.alves', 'cid' => '1044213', 'experiencia' => 'Intermediário', 'basePref' => 'SCCI', 'comoConheceu' => 'Rede VATSIM', 'motivacao' => 'Voo há 3 anos na rede, quero algo mais técnico que rotas de linha aérea comercial.', 'data' => '2026-08-18', 'status' => 'pendente'],
            ['id' => 2, 'nome' => 'Diego Fernández', 'email' => 'diego.fdz@example.com', 'discord' => null, 'cid' => '1198765', 'experiencia' => 'Experiente', 'basePref' => 'SCCI', 'comoConheceu' => 'Indicação de outro piloto', 'motivacao' => 'Já voei PC-6 e Twin Otter em pista curta no simulador, quero uma avaliação de verdade.', 'data' => '2026-08-17', 'status' => 'pendente'],
            ['id' => 3, 'nome' => 'Marcus Webb', 'email' => 'marcus.webb@example.com', 'discord' => 'marcuswebb#0', 'cid' => '1076541', 'experiencia' => 'Iniciante', 'basePref' => 'PAFA', 'comoConheceu' => 'Discord', 'motivacao' => 'Sou novo na rede VATSIM mas tenho bastante horas em X-Plane, quero aprender operação de campo.', 'data' => '2026-08-16', 'status' => 'pendente'],
            ['id' => 4, 'nome' => 'Ingrid Solberg', 'email' => 'ingrid.solberg@example.com', 'discord' => null, 'cid' => '1132908', 'experiencia' => 'Experiente', 'basePref' => 'PAFA', 'comoConheceu' => 'Redes sociais', 'motivacao' => 'Piloto de verdade com PPL, quero replicar bush flying no simulador com dados de telemetria de verdade.', 'data' => '2026-08-14', 'status' => 'aprovado'],
            ['id' => 5, 'nome' => 'Tomás Herrera', 'email' => 'tomas.herrera@example.com', 'discord' => 'tomasherrera', 'cid' => '1087345', 'experiencia' => 'Intermediário', 'basePref' => 'Sem preferência', 'comoConheceu' => 'Rede VATSIM', 'motivacao' => 'Gosto de voos de pesquisa meteorológica, achei o conceito da Katabatic bem interessante.', 'data' => '2026-08-10', 'status' => 'aprovado'],
            ['id' => 6, 'nome' => 'Priya Natarajan', 'email' => 'priya.n@example.com', 'discord' => 'priyan', 'cid' => '1054412', 'experiencia' => 'Iniciante', 'basePref' => 'SCCI', 'comoConheceu' => 'Outro', 'motivacao' => 'Ainda aprendendo pousos em pista curta, queria feedback de um grupo mais experiente.', 'data' => '2026-08-05', 'status' => 'rejeitado'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pilotos(): array
    {
        return [
            ['nome' => 'Comandante', 'cid' => '1234567', 'base' => 'PAFA', 'papel' => 'admin', 'dataAdesao' => '2026-01-12', 'voos' => 23, 'status' => 'ativo'],
            ['nome' => 'Elin Kask', 'cid' => '1029384', 'base' => 'PAFA', 'papel' => 'piloto', 'dataAdesao' => '2026-02-03', 'voos' => 18, 'status' => 'ativo'],
            ['nome' => 'Rafael Mondragón', 'cid' => '1067219', 'base' => 'SCCI', 'papel' => 'piloto', 'dataAdesao' => '2026-02-20', 'voos' => 15, 'status' => 'ativo'],
            ['nome' => 'Bianca Souza', 'cid' => '1091456', 'base' => 'SCCI', 'papel' => 'piloto', 'dataAdesao' => '2026-03-08', 'voos' => 11, 'status' => 'ativo'],
            ['nome' => 'Owen Fairweather', 'cid' => '1112873', 'base' => 'PAFA', 'papel' => 'piloto', 'dataAdesao' => '2026-04-14', 'voos' => 9, 'status' => 'ativo'],
            ['nome' => 'Lucía Paredes', 'cid' => '1145600', 'base' => 'SCCI', 'papel' => 'piloto', 'dataAdesao' => '2026-05-22', 'voos' => 6, 'status' => 'ativo'],
            ['nome' => 'Henrik Moen', 'cid' => '1078932', 'base' => 'PAFA', 'papel' => 'piloto', 'dataAdesao' => '2026-01-30', 'voos' => 2, 'status' => 'inativo'],
        ];
    }
}
