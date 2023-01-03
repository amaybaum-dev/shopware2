<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Update\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Store\Services\AbstractExtensionLifecycle;
use Shopware\Core\Framework\Update\Checkers\LicenseCheck;
use Shopware\Core\Framework\Update\Checkers\WriteableCheck;
use Shopware\Core\Framework\Update\Event\UpdatePostPrepareEvent;
use Shopware\Core\Framework\Update\Event\UpdatePrePrepareEvent;
use Shopware\Core\Framework\Update\Services\ApiClient;
use Shopware\Core\Framework\Update\Services\PluginCompatibility;
use Shopware\Core\Framework\Update\Steps\DeactivateExtensionsStep;
use Shopware\Core\Framework\Update\Steps\FinishResult;
use Shopware\Core\Framework\Update\Steps\ValidResult;
use Shopware\Core\Kernel;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('system-settings')]
class UpdateController extends AbstractController
{
    public const UPDATE_PREVIOUS_VERSION_KEY = 'core.update.previousVersion';

    /**
     * @internal
     */
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly WriteableCheck $writeableCheck,
        private readonly LicenseCheck $licenseCheck,
        private readonly PluginCompatibility $pluginCompatibility,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SystemConfigService $systemConfig,
        private readonly AbstractExtensionLifecycle $extensionLifecycleService,
        private readonly string $shopwareVersion,
        private readonly bool $disableUpdateCheck = false
    ) {
    }

    #[Route(path: '/api/_action/update/check', name: 'api.custom.updateapi.check', methods: ['GET'], defaults: ['_acl' => ['system:core:update']])]
    public function updateApiCheck(): JsonResponse
    {
        if ($this->disableUpdateCheck) {
            return new JsonResponse();
        }

        $updates = $this->apiClient->checkForUpdates();

        if (version_compare($this->shopwareVersion, $updates->version, '>')) {
            return new JsonResponse();
        }

        return new JsonResponse($updates);
    }

    #[Route(path: '/api/_action/update/check-requirements', name: 'api.custom.update.check_requirements', methods: ['GET'], defaults: ['_acl' => ['system:core:update']])]
    public function checkRequirements(): JsonResponse
    {
        return new JsonResponse([
            $this->writeableCheck->check(),
            $this->licenseCheck->check(),
        ]);
    }

    #[Route(path: '/api/_action/update/plugin-compatibility', name: 'api.custom.updateapi.plugin_compatibility', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function pluginCompatibility(Context $context): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates();

        return new JsonResponse($this->pluginCompatibility->getExtensionCompatibilities($update, $context));
    }

    #[Route(path: '/api/_action/update/download-latest-update', name: 'api.custom.updateapi.download_latest_update', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function downloadLatestRecovery(Request $request): Response
    {
        $this->apiClient->downloadRecoveryTool();

        return new NoContentResponse();
    }

    #[Route(path: '/api/_action/update/deactivate-plugins', name: 'api.custom.updateapi.deactivate-plugins', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function deactivatePlugins(Request $request, Context $context): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates();

        $offset = $request->query->getInt('offset');

        if ($offset === 0) {
            // plugins can subscribe to these events, check compatibility and throw exceptions to prevent the update
            $this->eventDispatcher->dispatch(
                new UpdatePrePrepareEvent($context, $this->shopwareVersion, $update->version)
            );
        }

        // disable plugins - save active plugins
        $deactivationFilter = (string) $request->query->get(
            'deactivationFilter',
            PluginCompatibility::PLUGIN_DEACTIVATION_FILTER_NOT_COMPATIBLE
        );

        $deactivatePluginStep = new DeactivateExtensionsStep(
            $update,
            $deactivationFilter,
            $this->pluginCompatibility,
            $this->extensionLifecycleService,
            $this->systemConfig,
            $context
        );

        $result = $deactivatePluginStep->run($offset);

        if ($result instanceof FinishResult) {
            $containerWithoutPlugins = $this->rebootKernelWithoutPlugins();

            // @internal plugins are deactivated
            $containerWithoutPlugins->get('event_dispatcher')->dispatch(
                new UpdatePostPrepareEvent($context, $this->shopwareVersion, $update->version)
            );
        }

        return $this->toJson($result);
    }

    private function rebootKernelWithoutPlugins(): ContainerInterface
    {
        /** @var Kernel $kernel */
        $kernel = $this->container->get('kernel');

        $classLoad = $kernel->getPluginLoader()->getClassLoader();
        $kernel->reboot(null, new StaticKernelPluginLoader($classLoad));

        return $kernel->getContainer();
    }

    private function toJson(ValidResult|FinishResult $result): JsonResponse
    {
        if ($result instanceof FinishResult) {
            return new JsonResponse([
                'valid' => false,
                'offset' => $result->getOffset(),
                'total' => $result->getTotal(),
                'success' => true,
                '_class' => $result::class,
            ]);
        }

        return new JsonResponse([
            'valid' => true,
            'offset' => $result->getOffset(),
            'total' => $result->getTotal(),
            'success' => true,
            '_class' => $result::class,
        ]);
    }
}
