<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use Nelmio\ApiDocBundle\Render\Html\AssetsMode;
use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PIMCORE_USER')]
#[Route('/admin/datahub/rest', defaults: ['area' => 'cihub'])]
final class SwaggerController extends FrontendController
{
    #[Route('/swagger', name: 'datahub_rest_adapter_swagger_ui', methods: ['GET'])]
    public function userInterfaceAction(Request $request, RenderOpenApi $renderOpenApi, array $options = []): Response
    {
        $options += [
            'assets_mode' => AssetsMode::CDN,
            'swagger_ui_config' => [],
        ];

        return $this->renderTemplate('@SimpleRESTAdapter/Swagger/index.html.twig', [
            'configUrl' => $this->generateUrl('datahub_rest_adapter_swagger_config'),
            'swagger_data' => ['spec' => json_decode((string) $renderOpenApi->renderFromRequest($request, RenderOpenApi::JSON, 'ci_hub'), true, 512, \JSON_THROW_ON_ERROR)],
            'assets_mode' => $options['assets_mode'],
            'swagger_ui_config' => $options['swagger_ui_config'],
        ]);
    }

    #[Route('/swagger-config', name: 'datahub_rest_adapter_swagger_config', methods: ['GET'])]
    public function configAction(Request $request, RenderOpenApi $renderOpenApi): Response
    {
        return new Response($renderOpenApi->renderFromRequest($request, RenderOpenApi::JSON, 'ci_hub'));
    }
}
