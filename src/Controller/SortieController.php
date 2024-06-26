<?php

namespace App\Controller;

use App\Entity\Campus;
use App\Entity\Etat;
use App\Entity\Lieu;
use App\Entity\Participant;
use App\Entity\Sortie;
use App\Form\models\SearchEvent;
use App\Form\SearchEventType;
use App\Form\SortieType;
use App\Repository\ParticipantRepository;
use App\Services\Changer;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\SortieRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sortie', name: 'sortie_')]
class SortieController extends AbstractController
{
    #[Route('/create', name: 'create')]
    public function create(EntityManagerInterface $entityManager, Request $request): Response
    {
        $sortie = new Sortie();

        $sortieForm = $this->createForm(SortieType::class, $sortie);

        $sortieForm->handleRequest($request);

        if ($sortieForm->isSubmitted() && $sortieForm->isValid()) {

            // Récupérer l'utilisateur connecté
            //$user = $this->getUser();

            $participant = $entityManager->getRepository(Participant::class)->find(196);
            $etat = $entityManager->getRepository(Etat::class)->find(115);
            //dd($participant);

            // Associer le participant à la sortie
            $sortie->setOrganisateur($participant);
            $sortie->setEtat($etat);

            //condition sur les dates
//            $dateHeureDebut = $sortie->getDateHeureDebut();
//            $dateLimiteInscription = $sortie->getDateLimiteInscription();
//
//            if ($dateHeureDebut <= $dateLimiteInscription) {
//                $this->addFlash('error', 'La date et heure de début doivent être après la date limite d\'inscription.');
//                return $this->redirectToRoute('/create');
//            }

            $entityManager->persist($sortie);
            $entityManager->flush();

            //ajout de la sortie à la liste des sorties du lieu
            $lieuDeLaSortieEnBase = $entityManager->getRepository(Lieu::class)->find($sortie->getLieu());
            $lieuDeLaSortieEnBase->addSorty($sortie);
            //enregistrement de la liste des sorties :
            $entityManager->persist($lieuDeLaSortieEnBase);
            $entityManager->flush();

            $this->addFlash('success', 'Sortie créée avec succès !');

            return $this->redirectToRoute('sortie_detail', ['id' => $sortie->getId()]);

        }

        return $this->render('sortie/sortie.html.twig', [
            'sortieForm' => $sortieForm->createView(),
            'sortie' => $sortie, // Passer la variable sortie à la vue Twig

        ]);
    }

    #[Route('/liste', name: 'liste')]
    public function getAll(
        SortieRepository $sortieRepository,
        EntityManagerInterface $entityManager,
        Request $request
    ): Response
    {

        $campuses = $entityManager->getRepository(campus::class)->findAll();

        $qb = $sortieRepository->createQueryBuilder('s');
        $query = $qb->select('s');

        $searchEvent = new SearchEvent();
        $formSearchEvent = $this->createForm(SearchEventType::class, $searchEvent);
        $formSearchEvent->handleRequest($request);

        // Filtre par campus
        $campus = $searchEvent->getCampus();
        if ($campus){
            $query->andWhere('s.campus = :campus');
            $query->setParameter('campus', $campus);
        }

        // Filtre par le champs de texte
        $search = $searchEvent->getSearch();
        if ($search){
            $query->andWhere('s.nom LIKE :search');
            $query->setParameter('search', '%'.$search.'%');
        }

        // Filtre par les dates
        $dateDebut = $searchEvent->getStartDate();
        $dateFin = $searchEvent->getEndDate();
        if ($dateDebut && $dateFin == null){
            $query->andWhere('s.dateHeureDebut >= :dateDebut');
            $query->setParameter('dateDebut', $dateDebut);
        }

        if ($dateFin && $dateDebut == null){
            $query->andWhere('s.dateHeureDebut <= :dateFin');
            $query->setParameter('dateDebut', $dateFin);
        }

        if ($dateFin && $dateDebut){
            $query->andWhere('s.dateHeureDebut BETWEEN :min AND :max');
            $query->setParameter('min', $dateDebut);
            $query->setParameter('max', $dateFin);
        }

        if ($dateFin < $dateDebut){
            $this->addFlash('error', 'La End date ne peut pas être inférieure à la date de début');
        }

        //Filtrage pour les sorties dont je suis organisateur
        $organisateur = $searchEvent->getSortieOrganisateur();
        if ($organisateur){
            $organisateur = $this->getUser();
            $query->andWhere('s.organisateur = :participant');
            $query->setParameter('participant', $organisateur);
        }

        //Filtrage pour les sorties dont je suis inscrit
        $inscrit = $searchEvent->getSortiesInscrits();
        if ($inscrit){
            $user = $this->getUser();
            $query->andWhere(':participant MEMBER OF s.participants');
            $query->setParameter('participant', $user);
        }

        //Filtrage pour les sorties dont je ne suis pas inscrit
        $nonInscrit = $searchEvent->getSortiesNonInscrits();
        if ($nonInscrit){
            $user = $this->getUser();
            $query->andWhere(':participant NOT MEMBER OF s.participants');
            $query->setParameter('participant', $user);
        }

        //Filtrage pour les sorties qui sont passées
        $sortiesPassee = $searchEvent->getSortiesNonInscrits();
        if ($sortiesPassee){
            $etat = 'Historisée';
            $query->andWhere('s.etat = :etat');
            $query->setParameter('etat', $etat);
        }

        $sorties = $query->getQuery()->getResult();

        return $this->render('sortie/liste.html.twig', [
            'sorties' => $sorties,
            'filterForm'=> $formSearchEvent
        ]);
    }

    #[Route('/update/{id}', name: 'update')]
    public function update(Sortie $sortie, EntityManagerInterface $entityManager, Request $request): Response
    {
        $sortieForm = $this->createForm(SortieType::class, $sortie);
        $sortieForm->handleRequest($request);

        if ($sortieForm->isSubmitted() && $sortieForm->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Sortie mise à jour avec succès !');

            return $this->redirectToRoute('sortie_detail', ['id' => $sortie->getId()]);
        }

        return $this->render('sortie/updated.html.twig', [
            'sortieForm' => $sortieForm->createView(),
            'sortie' => $sortie,
        ]);
    }
    #[Route('/detail/{id}', name: 'detail', requirements: ['id' => '\d+'])]
    public function detail(
        EntityManagerInterface $entityManager,
        Request $request,
        SortieRepository $sortieRepository,
        int     $id
    ): Response
    {
        $sortie = $entityManager->getRepository(Sortie::class)->find($id);
        $participants = $sortie->getParticipants();

        if (!$sortie) {
            throw $this->createNotFoundException('Sortie non trouvée');
        }
        return $this->render('sortie/detail.html.twig', [
            'sortie' => $sortie,
            'participants'=>$participants
        ]);
    }

    #[Route('/publier/{id}', name: 'publier', requirements: ['id' => '\d+'])]
    public function publier(
        EntityManagerInterface $entityManager,
        Request $request,
        SortieRepository $sortieRepository,
        int     $id
    ): Response
    {
        $sorties = $entityManager->getRepository(Sortie::class)->findAll();

        return $this->render('sortie/liste.html.twig', [
            'sorties' => $sorties,
        ]);
    }

    #[Route('/annuler/{id}', name: 'annuler', requirements: ['id' => '\d+'])]
    public function annuler(
        EntityManagerInterface $entityManager,
        Request $request,
        SortieRepository $sortieRepository,
        int     $id,
    ): Response
    {
        $sorties = $entityManager->getRepository(Sortie::class)->findAll();
        $sortie = $sortieRepository->find($id);
        $now = new \DateTime();

        // Vérifier si l'utilisateur est l'organisateur de la sortie
        if($sortie->getOrganisateur() !== $this->getUser() ) {
            throw $this->createNotFoundException('Vous n\'êtes pas autorisé à annuler cette sortie.');
        }

        // Vérifier si la sortie n'a pas encore commencé
        if($sortie->getDateHeureDebut() > $now ) {
            $sortie->setEtat($entityManager->getRepository(Etat::class)->findOneBy(['libelle' => 'Annulée']));

            $entityManager->flush();

            $this->addFlash('success', 'La sortie a été annulée avec succès.');
            return $this->render('sortie/annuler.html.twig', [
                'sortie' => $sortie
            ]);
            //return $this->redirectToRoute('sortie_annuler', ['id' => $sortie->getId()]);
        }
        $this->addFlash('error', 'La sortie ne peut pas être annulée car elle a déjà commencé.');

        return $this->render('sortie/liste.html.twig', [
            'sorties' => $sorties
        ]);
    }

    #[Route('/inscription/{id}/{idParticipant}', name: 'inscription', requirements: ['id' => '\d+', 'idParticipants' => '\d+'])]
    public function inscription(
        EntityManagerInterface $entityManager,
        Request $request,
        SortieRepository $sortieRepository,
        ParticipantRepository $participantRepository,
        int     $id,
        int $idParticipant
    ): Response
    {
        $sortie = $sortieRepository->find($id);
        $participant = $entityManager->getRepository(Participant::class)->find($idParticipant);

        if(!$sortie || !$participant){
            throw $this->createNotFoundException('Sortie ou Participant non trouvée !!');
        }
        $now = new \DateTime();

        //vérifier des conditions
        if ($sortie->getEtat()->getLibelle() !== 'Ouverte' ||
            $sortie->getDateLimiteInscription() <= $now ||
            count($sortie->getParticipants()) >= $sortie->getNbInscriptionsMax()
        ) {
            // Gérer ici le cas où les conditions d'inscription ne sont pas remplies
            $this->addFlash('error', 'Vous ne pouvez pas vous inscrire à cette sortie pour le moment.');

            return $this->redirectToRoute('sortie_liste');
        }

        // Ajouter le participant à la sortie
        $sortie->addParticipant($participant);
        $entityManager->flush();


        return $this->redirectToRoute('sortie_detail', ['id' => $sortie->getId()]);

    }

    #[Route('/desistement/{id}/{idParticipant}', name: 'desistement', requirements: ['id' => '\d+', 'idParticipants' => '\d+'])]
    public function desistement(
        EntityManagerInterface $entityManager,
        Request $request,
        SortieRepository $sortieRepository,
        int     $id,
        int $idParticipant

    ): Response
    {
        //On récupere l'id de la sortie
        $sortie = $sortieRepository->find($id);

        //On prend l'utilisateur de la sortie
        $participant = $entityManager->getRepository(Participant::class)->find($idParticipant);


        if (new \DateTime() >= $sortie->getDateHeureDebut()) {
            $this->addFlash('error', 'Vous ne pouvez plus vous désister car l\'événement a déjà commencé.');
            return $this->redirectToRoute('sortie_detail', ['id' => $id]);
        }
        if (new \DateTime() >= $sortie->getDateLimiteInscription()) {
            $this->addFlash('error', 'Vous ne pouvez plus vous désister car la date limite d\'inscription est terminée.');
            return $this->redirectToRoute('sortie_detail', ['id' => $id]);

        }

        // Retirer le participant de la sortie
        $sortie->removeParticipant($participant);
        $entityManager->flush();

        // Ajouter un message de succès
        $this->addFlash('success', 'Succès ! Vous n\'appartenez plus à cette sortie .');
        return $this->redirectToRoute('sortie_liste');

    }



}

