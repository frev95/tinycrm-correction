<?php

namespace App\Controller;

use Stripe\StripeClient;
use App\Form\PaymentType;
use App\Entity\Transaction;
use App\Service\StripeService;
use Symfony\Component\Mime\Email;
use App\Repository\OffreRepository;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\TransactionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Loader\Configurator\mailer;

#[Route('/payment')]
class PaymentController extends AbstractController
{
    #[Route('/', name: 'app_payment')]
    public function index(
        Request $request,
        StripeService $stripeService,
        OffreRepository $offres,
        ClientRepository $clients,
        TransactionRepository $transactions,
        MailerInterface $mailer,
        EntityManagerInterface $em
        ): Response
    {

        $form = $this->createForm(PaymentType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $offre = $offres->findOneBy(['id' => $data['offre']->getId()]); // Offre à vendre (titre et montant)
            $client = $clients->findOneBy(['id' => $data['client']->getId()]);
            $clientEmail = $client->getEmail();
            $apiKey = $this->getParameter('STRIPE_API_KEY_SECRET'); // Clé API secrète
            $link = $stripeService->makePayment(
                $apiKey,
                $offre->getMontant(),
                $offre->getTitre(),
                $clientEmail
            );

            // envoi du mail au client
            $email = (new Email())
                ->from('hello@tinycrm.app')
                ->to($clientEmail)
                ->priority(Email::PRIORITY_HIGH)
                ->subject('Merci de procéder au paiement de votre offre')
                ->html('<div style="background-color: #f4f4f4; padding: 20px; text-align: center;">
                        <h1>Bonjour '.$client->getPrenom().',</p><br><br>
                        Voici le lien pour effectuer le règlement de<br>
                        votre offre '.$offre->getTitre().':<br>
                        <a href="'.$link.'" target="_blank">Payer '.$offre->getMontant().' euros</a><br>
                        <hr>
                        <p>Ce lien n\'est valable que pour une durée limitée.</p>
                        </div>');
            $mailer->send($email);
            // ajouter l'affichage d'un flash message (recopier le bout de code qui se trouve dans BnB)

            $transaction = new Transaction();
            $transaction->setClient($data['client'])
                        ->setMontant($offre->getMontant())
                        ->setStatut('En attente');
            $em->persist($transaction); // EntityManagerInterface
            $em->flush();
        }

        return $this->render('payment/index.html.twig', [
            'form' => $form->createView(),
        ]);

    }
    #[Route('/success', name: 'payment_success')]
    public function success(): Response
    {
        $stripe = new StripeClient($this->getParameter('STRIPE_API_KEY_SECRET'));

        // This is your Stripe CLI webhook secret for testing your endpoint locally.
        $endpoint_secret = 'whsec_780e28e45e28f9e9fec1e54692a175cee0ba954ffa20297c9110c2d21031ddc5';

        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $endpoint_secret
        );
        } catch(\UnexpectedValueException $e) {
        // Invalid payload
        http_response_code(400);
        exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
        // Invalid signature
        http_response_code(400);
        exit();
        }

        // Handle the event
        switch ($event->type) {
        case 'payment_intent.succeeded':
            $paymentIntent = $event->data->object;
        // ... handle other event types
        default:
            echo 'Received unknown event type ' . $event->type;
        }

        http_response_code(200);

        return $this->render('payment/success.html.twig');
    }
    #[Route('/cancel', name: 'payment_cancel')]
    public function cancel(): Response
    {
        return $this->render('payment/cancel.html.twig', [
            'controller_name' => 'PaymentController',
        ]);
    }
}