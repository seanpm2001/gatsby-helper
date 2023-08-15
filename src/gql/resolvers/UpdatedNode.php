<?php

/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\gatsbyhelper\gql\resolvers;

use Craft;
use craft\errors\GqlException;
use craft\gatsbyhelper\Plugin;
use craft\gql\base\Resolver;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Class UpdatedNode
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0.0
 */
class UpdatedNode extends Resolver
{
    public static function resolve($source, array $arguments, $context, ResolveInfo $resolveInfo)
    {
        if (empty($arguments['site'])) {
            $arguments['site'] = [Craft::$app->getSites()->getPrimarySite()->handle];
        }

        $siteIds = [];
        foreach ($arguments['site'] as $handle) {
            $site = Craft::$app->getSites()->getSiteByHandle($handle, false);
            if ($site) {
                $siteIds[] = $site->id;
            }
        }

        $updatedNodes = Plugin::getInstance()->getDeltas()->getUpdatedNodesSinceTimeStamp($arguments['since'], $siteIds);
        $resolved = [];
        $allowedInterfaces = array_keys(Plugin::getInstance()->getSourceNodes()->getSourceNodeTypes());

        foreach ($allowedInterfaces as &$allowedInterface) {
            $allowedInterface = GqlEntityRegistry::prefixTypeName($allowedInterface);
        }

        $schema = Craft::$app->getGql()->getSchemaDef();

        foreach ($updatedNodes as $updatedNode) {
            $element = Craft::$app->getElements()->getElementById($updatedNode['id'], $updatedNode['type']);

            // if element wasn't found - keep on going
            if ($element == null) {
                continue;
            }

            // Don't care about elements that don't bother implementing GQL.
            if ($element->getGqlTypeName() === 'Element') {
                continue;
            }

            // try to find the matching type;
            // if it's not included in the schema - move on to the next element instead of freaking out
            // https://github.com/craftcms/gatsby-helper/issues/31
            try {
                /** @var Element $gqlType */
                $gqlType = $schema->getType(GqlEntityRegistry::prefixTypeName($element->getGqlTypeName()));
            } catch (GqlException $e) {
                Craft::error($e->getMessage());
                continue;
            }
            $registeredInterfaces = $gqlType->getInterfaces();

            foreach ($registeredInterfaces as $registeredInterface) {
                $interfaceName = $registeredInterface->name;

                // Make sure Gatsby can handle updates to these elements.
                if ($interfaceName !== GqlEntityRegistry::prefixTypeName(ElementInterface::getName()) && !in_array($interfaceName, $allowedInterfaces, true)) {
                    continue 2;
                }
            }

            $resolved[] = [
                'nodeId' => $updatedNode['id'],
                'nodeType' => $element->getGqlTypeName(),
                'siteId' => $updatedNode['siteId'],
                'elementType' => get_class($element),
            ];
        }

        return $resolved;
    }
}
