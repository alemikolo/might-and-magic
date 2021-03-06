<?php

namespace GameBundle\Controller;

use GameBundle\Entity\Game;
use UserBundle\Entity\User;
use GameBundle\Entity\Comment;
use GameBundle\Entity\GameUserRate;
use GameBundle\Form\CommentFormType;
use GameBundle\Form\RateFormType;
use GameBundle\Event\Events;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\GenericEvent;
use FOS\UserBundle\Model\UserInterface;


class GameController extends Controller
{
    /**
     * @Route("/gry", name="game_list")
     * @Method("GET")
     * @Cache(smaxage="15")
     */
    public function listAction()
    {
        $games = $this->get('doctrine')->getRepository('GameBundle:Game')->findAll();
        return $this->render('GameBundle:Game:games.html.twig', ['games' => $games]);
    }
    
    /**
     * @Route("gry/", defaults={"slug": "magia-i-miecz-edycja-kolosa"}, name="show_main_game")
     * @Route("gry/{slug}", name="show_game")
     * @Method("GET")
     * @ParamConverter("game", class="GameBundle:Game")
     */
    public function showAction(Request $request, Game $game)
    {
        $user = $this->getUser();
        if (!isset($user) || !is_object($user) || !$user instanceof UserInterface) {
            $userRate = null;
        } else {
            $userRate = $this->get('doctrine')->
                getRepository('GameBundle:Game')->
                getUserGameRate($game->getId(), $user->getId());
        }
        
        $ratesByAuthorsOfComments = $this->get('doctrine')->
            getRepository('GameBundle:Game')->
            getGamesRatesforAuthorsOfComments($game->getId());
        
        $rankingPosition = $this->get('doctrine')->
            getRepository('GameBundle:Game')->
            calculateRankingPosition($game->getId());
        
        $authors = $this->get('doctrine')->
            getRepository('GameBundle:Game')->
            getAuthors($game->getId());        
        return $this->render('GameBundle:Game:game.html.twig',
            ['game' => $game,
             'userrate'=> $userRate,
             'ratesByAuthors'=>$ratesByAuthorsOfComments,
             'ranking'=>$rankingPosition,
             'authors'=>$authors]);
    }
    
    /**
     * @Route("/comment/{slug}/new", name="new_comment")
     * @Method("POST")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     */
    public function commentNewAction(Request $request, Game $game)
    {
        $commentResponse;
        
        $form = $this->createCommentFormType();

        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            /* @var $comment Comment*/
            $comment = $form->getData();
            $comment->setAuthor($this->getUser());
            $comment->setGame($game);
            $comment->setCensored(0);
            $comment->setAccepted(1);
            
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($comment);
            $entityManager->flush();
            
            $event = new GenericEvent($comment);
            $this->get('event_dispatcher')->dispatch(Events::COMMENT_CREATED, $event);
            $commentResponse = $this->redirectToRoute('show_game', ['slug' => $game->getSlug()]);
        } else {
            $commentResponse = $this->render('GameBundle:Game:comment_form_error.html.twig', [
                'game' => $game,
                'form' => $form->createView(),
                ]
            );
        }
        return $commentResponse;
    }
    
    /**
     * @param Game $game
     *
     * @return Response
     */
    public function commentFormAction(Game $game)
    {
        $form = $this->createCommentFormType();
        
        return $this->render('GameBundle:Game:comment_form.html.twig', [
            'game' => $game,
            'form' => $form->createView(),
        ]);
    }
    
    private function createCommentFormType()
    {
        return $this->createForm(CommentFormType::class);
    }
    
    /**
     * @Route("/rate/{slug}/new", name="new_rate")
     * @Method("POST")
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     */
    public function rateNewAction(Request $request, Game $game)
    {
        $rateResponse;
        $form = $this->createRateFormType();

        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $formRate = $form->getData();
            /* @var $dbRate GameUserRate */
            $dbRate = $this->get('doctrine')->
                getRepository('GameBundle:GameUserRate')->
                findOneBy(["user"=>$this->getUser(), "game"=>$game ]);
            
            if (is_null($dbRate)) {
                $dbRate = $formRate;
                $dbRate->setGame($game);
                $dbRate->setUser($this->getUser());
            } else {
                $dbRate->setRate($formRate->getRate());
            }
                       
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($dbRate);
            $entityManager->flush();
            
            $votesAndAverageRate = $this->get('doctrine')->
                getRepository('GameBundle:Game')->
                calculateVotesAndAverageRate($game->getId());
            $game->setAvg($votesAndAverageRate['avg']);
            $game->setVotes($votesAndAverageRate['num']);
            $entityManager->persist($game);
            $entityManager->flush();
            
            $event = new GenericEvent($dbRate);
            $this->get('event_dispatcher')->dispatch(Events::RATE_ADDED, $event);
            $rateResponse = $this->redirectToRoute('show_game', ['slug' => $game->getSlug()]);
        } else {
            $rateResponse = $this->render('GameBundle:Game:rate_form_error.html.twig', [
                'game' => $game,
                'form' => $form->createView(),
            ]);
        }
        return $rateResponse;
    }
    
    /**
     * @param Game $game
     *
     * @return Response
     */
    public function rateFormAction(Game $game)
    {
        $form = $this->createRateFormType();

        return $this->render('GameBundle:Game:rate_form.html.twig', [
            'game' => $game,
            'form' => $form->createView(),
        ]);
    }
    
    private function createRateFormType()
    {
        return $this->createForm(RateFormType::class);
    }
    
    
    
    
    
    
    /**
     * @Route("admin/gry/{slug}/edition", name="editGame")
     */
    public function editGameAction($slug)
    {
        return new Response("Edycja gry: " . $slug . ".");
    }
    
    /**
     * @Route("gry/dodaj/grę", name="addGame")
     */
    public function addGameAction()
    {
        return new Response("Dodawanie gry");
    }
    
    /**
     * @Route("gry/usuń/grę", name="deleteGame")
     * @Method({"PUT"})
     */
    public function deleteGameAction()
    {
        return new Response("Usuwanie gry");
    }
}
