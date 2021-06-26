<?php
/*
 * (c) Minh Vuong <vuongxuongminh@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace Istio\Symfony\JWTAuthentication\DependencyInjection\Security;

use Istio\Symfony\JWTAuthentication\Authenticator\UserIdentifierClaimMapping;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AuthenticatorFactoryInterface;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\SecurityFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class AuthenticatorFactory implements SecurityFactoryInterface, AuthenticatorFactoryInterface
{
    public function createAuthenticator(
        ContainerBuilder $container,
        string $firewallName,
        array $config,
        string $userProviderId
    ) {
        $authenticator = sprintf('security.authenticator.istio_jwt_authenticator.%s', $firewallName);
        $definition = new ChildDefinition('istio.jwt_authentication.authenticator');
        $definition->replaceArgument(0, $this->createUserIdentifierClaimMappings($container, $authenticator, $config));
        $definition->replaceArgument(1, new Reference($userProviderId));
        $container->setDefinition($authenticator, $definition);

        return $authenticator;
    }

    public function create(
        ContainerBuilder $container,
        string $id,
        array $config,
        string $userProviderId,
        ?string $defaultEntryPointId
    ) {
        throw new \LogicException('Istio JWT Authentication is not supported when "security.enable_authenticator_manager" is not set to true.');
    }

    public function getPosition()
    {
        return 'pre_auth';
    }

    public function getKey()
    {
        return 'istio_jwt_authenticator';
    }

    public function addConfiguration(NodeDefinition $builder)
    {
        $builder
            ->cannotBeEmpty()
            ->fixXmlConfig('origin_token_header')
            ->fixXmlConfig('origin_token_query_param')
            ->fixXmlConfig('base64_header')
            ->arrayPrototype()
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('issuer')
                        ->cannotBeEmpty()
                        ->isRequired()
                    ->end()
                    ->scalarNode('user_identifier_claim')
                        ->cannotBeEmpty()
                        ->defaultValue('sub')
                    ->end()
                    ->arrayNode('origin_token_headers')
                        ->scalarPrototype()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                    ->arrayNode('origin_token_query_params')
                        ->scalarPrototype()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                    ->arrayNode('base64_headers')
                        ->scalarPrototype()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function createUserIdentifierClaimMappings(
        ContainerBuilder $container,
        string $authenticatorName,
        array $config,
    ): IteratorArgument {
        $extractorIdPrefix = sprintf('%s.payload_extractor', $authenticatorName);
        $mappings = [];

        foreach ($config as $key => $item) {
            $extractor = null;

            if (!empty($item['origin_token_headers'])) {
                $extractor = $this->createPayloadExtractor(
                    $container,
                    sprintf('%s.origin_token_headers.%s', $extractorIdPrefix, $key),
                    'istio.jwt_authentication.payload_extractor.origin_token.header',
                    $item['issuer'],
                    $item['origin_token_headers']
                );
            }

            if (!empty($item['origin_token_query_params'])) {
                $extractor = $this->createPayloadExtractor(
                    $container,
                    sprintf('%s.origin_token_query_params.%s', $extractorIdPrefix, $key),
                    'istio.jwt_authentication.payload_extractor.origin_token.query_param',
                    $item['issuer'],
                    $item['origin_token_query_params']
                );
            }

            if (!empty($item['base64_headers'])) {
                $extractor = $this->createPayloadExtractor(
                    $container,
                    sprintf('%s.base64_headers.%s', $extractorIdPrefix, $key),
                    'istio.jwt_authentication.payload_extractor.base64_header',
                    $item['issuer'],
                    $item['base64_headers']
                );
            }

            if (null === $extractor) {
                throw new InvalidConfigurationException(sprintf('`%s`: at least once `origin_token_headers`, `origin_token_query_params`, `base64_headers` should be config when using', $this->getKey()));
            }

            $mappingId = sprintf('%s.user_identifier_claim_mapping.%s', $authenticatorName, $key);
            $mappings[] = new Reference($mappingId);
            $mappingDefinition = new Definition(UserIdentifierClaimMapping::class);
            $mappingDefinition->setArgument(0, $item['user_identifier_claim']);
            $mappingDefinition->setArgument(1, $extractor);
            $container->setDefinition($mappingId, $mappingDefinition);
        }

        return new IteratorArgument($mappings);
    }

    private function createPayloadExtractor(
        ContainerBuilder $container,
        string $id,
        string $fromAbstractId,
        string $issuer,
        array $items
    ): Reference {
        $definition = new ChildDefinition('istio.jwt_authentication.payload_extractor.composite');
        $container->setDefinition($id, $definition);

        $subExtractors = [];

        foreach ($items as $key => $item) {
            $subId = sprintf('%s.%s', $id, $key);
            $subExtractors[] = new Reference($subId);
            $subDefinition = new ChildDefinition($fromAbstractId);
            $subDefinition->replaceArgument(0, $issuer);
            $subDefinition->replaceArgument(1, $item);
            $container->setDefinition($subId, $subDefinition);
        }

        $definition->setArguments($subExtractors);

        return new Reference($id);
    }
}
