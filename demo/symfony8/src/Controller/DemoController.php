<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function home(InstanceSettingsInterface $settings): Response
    {
        return $this->render('home.html.twig', [
            'site'          => $settings->getSection('site'),
            'mailer_from'   => $settings->getString('mailer.from'),
            'dsn_stored'    => $settings->hasSecret('mailer.dsn'),
            'sms_provider'  => $settings->getString('sms.provider'),
            'token_stored'  => $settings->hasSecret('sms.auth_token'),
        ]);
    }
}
