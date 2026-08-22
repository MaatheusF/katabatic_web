<?php

namespace App\Controller;

use App\Entity\Pilot;
use App\Repository\PilotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Perfil do piloto: nome, e-mail e foto de exibição, mais os dados
 * "de conta" (CID, papel) que ainda não são editáveis por aqui.
 *
 * **Atualizado: usuário do AvioDeck.** Campo opcional novo
 * (`Pilot::$aviodeckUsername`) — não é validado contra a API do
 * AvioDeck (não existe integração de verdade), só guardado e usado
 * pra montar o link real em "Referências externas" no relatório de
 * voo (ver `VooController`, `voo/index.html.twig`).
 *
 * Persistência de verdade a partir daqui - `$this->getUser()` devolve
 * o Pilot autenticado (ver App\Security\LoginFormAuthenticator), e
 * "salvar o perfil" agora é um UPDATE de verdade via Doctrine, não só
 * um array de sessão como antes. Depois de salvar, o array de sessão
 * 'pilot' (usado pelo resto do site - rail, guard manual das outras
 * telas) é atualizado também, pro nome/foto novos aparecerem no rail
 * na mesma hora sem precisar deslogar - ver nota em
 * config/packages/security.yaml sobre essa estratégia de transição.
 */
class PerfilController extends AbstractController
{
    /** Tipos MIME aceitos pro upload de foto do perfil. */
    private const ALLOWED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Tamanho máximo aceito pro upload de foto do perfil, em bytes (3 MB). */
    private const MAX_PHOTO_SIZE = 3 * 1024 * 1024;

    #[Route('/perfil', name: 'app_perfil', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Pilot|null $pilot */
        $pilot = $this->getUser();
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('perfil/index.html.twig', [
            'activeView' => null,
            'pilot' => $this->pilotViewModel($pilot),
            'errors' => [],
        ]);
    }

    #[Route('/perfil', name: 'app_perfil_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em, PilotRepository $pilots): Response
    {
        /** @var Pilot|null $pilot */
        $pilot = $this->getUser();
        if (null === $pilot) {
            return $this->redirectToRoute('app_login');
        }

        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        // Aceita colar "@usuario" ou "usuario" - o link montado em
        // VooController sempre soma o @ na hora de exibir.
        $aviodeckUsername = ltrim(trim((string) $request->request->get('aviodeck', '')), '@');

        $errors = [];
        if ('' === $name) {
            $errors[] = 'Informe seu nome.';
        }
        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        } else {
            $existing = $pilots->findOneBy(['email' => $email]);
            if (null !== $existing && $existing->getId() !== $pilot->getId()) {
                $errors[] = 'Este e-mail já está em uso por outro piloto.';
            }
        }

        $photoPath = $pilot->getPhoto();

        /** @var UploadedFile|null $photoFile */
        $photoFile = $request->files->get('photo');
        if (null !== $photoFile) {
            if (!$photoFile->isValid()) {
                $errors[] = 'Não foi possível ler o arquivo de foto enviado — tente novamente.';
            } elseif (!in_array($photoFile->getMimeType(), self::ALLOWED_PHOTO_MIME_TYPES, true)) {
                $errors[] = 'Formato de imagem não suportado — envie um arquivo JPG, PNG ou WEBP.';
            } elseif ($photoFile->getSize() > self::MAX_PHOTO_SIZE) {
                $errors[] = 'A imagem enviada é muito grande — o limite é 3 MB.';
            } else {
                $newPhotoPath = $this->storePhoto($photoFile, $pilot->getCid());
                if (null === $newPhotoPath) {
                    $errors[] = 'Não foi possível salvar a foto enviada — tente novamente.';
                } else {
                    $photoPath = $newPhotoPath;
                }
            }
        }

        if ([] !== $errors) {
            // Mantem o que a pessoa digitou (menos a foto, que so troca se
            // o upload em si foi validado acima) pra nao perder o
            // preenchimento por causa de um erro em outro campo - sem
            // tocar no Pilot de verdade (nada foi persistido ainda).
            $preview = $this->pilotViewModel($pilot);
            $preview['name'] = $name;
            $preview['email'] = $email;
            $preview['photo'] = $photoPath;
            $preview['aviodeckUsername'] = '' !== $aviodeckUsername ? $aviodeckUsername : null;

            return $this->render('perfil/index.html.twig', [
                'activeView' => null,
                'pilot' => $preview,
                'errors' => $errors,
            ]);
        }

        $pilot->setName($name);
        $pilot->setEmail($email);
        $pilot->setPhoto($photoPath);
        $pilot->setAviodeckUsername('' !== $aviodeckUsername ? $aviodeckUsername : null);
        $em->flush();

        // Shim de transição (ver docblock da classe) - mantem o array de
        // sessao em dia com o que acabou de ser salvo no banco.
        $request->getSession()->set('pilot', $this->pilotViewModel($pilot));

        $this->addFlash('success', 'Perfil atualizado com sucesso.');

        return $this->redirectToRoute('app_perfil');
    }

    /**
     * Monta o array que o template (e o shim de sessão) esperam - mesmo
     * formato usado desde a versão mock, incluindo `initials` calculado
     * na hora (ver Pilot::getInitials()).
     *
     * @return array{initials: string, name: string, cid: string, admin: bool, email: string, photo: ?string, aviodeckUsername: ?string}
     */
    private function pilotViewModel(Pilot $pilot): array
    {
        return [
            'initials' => $pilot->getInitials(),
            'name' => $pilot->getName(),
            'cid' => $pilot->getCid(),
            'admin' => $pilot->isAdmin(),
            'email' => $pilot->getEmail(),
            'photo' => $pilot->getPhoto(),
            'aviodeckUsername' => $pilot->getAviodeckUsername(),
        ];
    }

    /**
     * Move o upload pra public/uploads/avatars com um nome novo (evita
     * colisão e evita reusar o nome original do arquivo do usuário).
     * Retorna o caminho público (servível direto pelo webserver, já que
     * fica dentro de public/) ou null se o move() falhar.
     */
    private function storePhoto(UploadedFile $file, string $cid): ?string
    {
        $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads/avatars';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $extension = $file->guessExtension() ?? 'jpg';
        $filename = sprintf('pilot-%s-%s.%s', $cid, bin2hex(random_bytes(4)), $extension);

        try {
            $file->move($uploadDir, $filename);
        } catch (FileException) {
            return null;
        }

        return '/uploads/avatars/'.$filename;
    }
}
