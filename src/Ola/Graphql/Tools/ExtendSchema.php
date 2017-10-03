<?php
namespace Ola\GraphQL\Tools;

use GraphQL\Error\Error;
use GraphQL\Executor\Values;

use GraphQL\Language\AST\DirectiveDefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumValueDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;

use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Language\Token;

use GraphQL\Type\Schema;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;

/**
 * Create new instance of `GraphQL\Type\Schema` out of provided schema and a type language definition (string or parsed AST)
 * See [section in docs](type-system/type-language.md) for details.
 */
class ExtendSchema
{
    //TODO search in JS for keyvalmap keymap and map and use Utils functions instead array walk or array_map
    public static function extend(Schema $schema, DocumentNode $ast)
    {
        $extender = new self($schema, $ast);
        return $extender->extendSchema();
    }

    private $ast;
    private $schema;
    private $typeDefCache;
    private $nodeMap;
    private $typeConfigDecorator;
    private $loadedTypeDefs;


    private $typeDefinitionMap;
    private $typeExtensionsMap;
    private $typeDirectivesMap;

    public function __construct(Schema $schema, DocumentNode $ast)
    {
        $this->schema = $schema;
        $this->ast = $ast;
    }

    public function extendSchema()
    {
        $schema = $this->schema;
        Utils::invariant($this->schema instanceof Schema, 'Must provide valid GraphQLSchema');
        Utils::invariant($this->ast && $this->ast->kind === NodeKind::DOCUMENT, 'Must provide valid Document AST');

        $this->typeDefinitionMap = [];
        $this->typeExtensionsMap = [];
        $this->typeDirectivesMap = [];

        foreach ($this->ast->definitions as $def) {
            switch ($def->kind) {
                case NodeKind::OBJECT_TYPE_DEFINITION:
                case NodeKind::INTERFACE_TYPE_DEFINITION:
                case NodeKind::ENUM_TYPE_DEFINITION:
                case NodeKind::UNION_TYPE_DEFINITION:
                case NodeKind::SCALAR_TYPE_DEFINITION:
                case NodeKind::INPUT_OBJECT_TYPE_DEFINITION:
                    $typeName = $def->name->value;
                    if (!empty($this->schema->getType($typeName))) {
                        throw new Error("Type \"$typeName\" was defined more than once.");
                    }
                    $this->typeDefinitionMap[$typeName] = $def;
                    break;

                case NodeKind::TYPE_EXTENSION_DEFINITION:
                    // Sanity check that this type extension exists within the
                    // schema's existing types.
                    $extendedTypeName = $def->definition->name->value;
                    $existingType = $this->schema->getType($extendedTypeName);
                    if (!$existingType) {
                        throw new Error("Cannot extend type \"$extendedTypeName\" because it does not exist in the existing schema.");
                    }
                    if (!($existingType instanceof ObjectType)) {
                        throw new Error("Cannot extend non-object type \"$extendedTypeName\"");
                    }

                    $this->typeExtensionsMap[$extendedTypeName][] = $def;

                    break;

                case NodeKind::DIRECTIVE_DEFINITION:
                    $directiveName = $def->name->value;
                    $existingDirective = $this->schema->getDirective($directiveName);
                    if ($existingDirective) {
                        throw new Error("Directive \"$directiveName\" already exists in the schema. It cannot be redefined.");
                    }
                    $this->typeDirectivesMap[] = $def;
                    break;
            }
        }

        // If this document contains no new types, extensions, or directives then
        // return the same unmodified Schema instance.
        if (!count($this->typeDefinitionMap) && !count($this->typeExtensionsMap) && !count($this->typeDirectivesMap)) {
            return $this->schema;
        }

        // A cache to use to store the actual GraphQLType definition objects by name.
        // Initialize to the GraphQL built in scalars and introspection types. All
        // functions below are inline so that this type def cache is within the scope
        // of the closure.
        //defTypeCache
        $this->typeDefCache = [
            'String' => Type::string(),
            'Int' => Type::int(),
            'Float' => Type::float(),
            'Boolean' => Type::boolean(),
            'ID' => Type::id(),
            '__Schema' => Introspection::_schema(),
            '__Directive' => Introspection::_directive(),
            '__DirectiveLocation' => Introspection::_directiveLocation(),
            '__Type' => Introspection::_type(),
            '__Field' => Introspection::_field(),
            '__InputValue' => Introspection::_inputValue(),
            '__EnumValue' => Introspection::_enumValue(),
            '__TypeKind' => Introspection::_typeKind(),
        ];


        // Get the root Query, Mutation, and Subscription object types.
        $queryType = $this->getTypeFromDef($this->schema->getQueryType());

        $existingMutationType = $this->schema->getMutationType();
        $mutationType = $existingMutationType ? $this->getTypeFromDef($existingMutationType) : null;

        $existingSubscriptionType = $this->schema->getSubscriptionType();
        $subscriptionType = $existingSubscriptionType ? $this->getTypeFromDef($existingSubscriptionType) : null;

        // Iterate through all types, getting the type definition for each, ensuring
        // that any type not directly referenced by a field will get created.
        $typeMap = $this->schema->getTypeMap();
        $types = [];
        foreach ($typeMap as $typeName => $type) {
            $types[$typeName] = $this->getTypeFromDef($type);
        }

        // Do the same with new types, appending to the list of defined types.
        foreach ($this->typeDefinitionMap as $typeName => $type) {
            $types[$typeName] = $this->getTypeFromAST($type);
        }

        // Then produce and return a Schema with these types.
        return new Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
            'subscription' => $subscriptionType,
            'types' => $types,
            'directives' => $this->getMergedDirectives(),
            'astNode' => $this->schema->getAstNode(),
        ]);

    }

    private function getMergedDirectives() {
        $existingDirectives = $this->schema->getDirectives();
        Utils::invariant($existingDirectives, 'schema must have default directives');

        $newDirectives = [];
        foreach ($this->typeDirectivesMap as $directiveNode) {
            $newDirectives[] = $this->getDirective($directiveNode);
        }

        return array_merge($existingDirectives, $newDirectives);
    }

    private function getDirective(DirectiveDefinitionNode $directiveNode) {
        return new Directive([
            'name' => $directiveNode->name->value,
            'description' => $this->getDescription($directiveNode),
            'locations' => Utils::map($directiveNode->locations, function($node) {
                return $node->value;
            }),
            'args' => $directiveNode->arguments ? FieldArgument::createMap($this->makeInputValues($directiveNode->arguments)) : null,
        ]);
    }


    private function getTypeFromDef($typeDef) {
        $type = $this->_getNamedType($typeDef->name);
        Utils::invariant($type, "Missing type \"$typeDef->name\" from schema");
        return $type;
    }

    // Given a name, returns a type from either the existing schema or an added type.
    private function _getNamedType($typeName) {
        $cachedTypeDef = $this->typeDefCache[$typeName];
        if ($cachedTypeDef) {
            return $cachedTypeDef;
        }
        /**
        * @var \GraphQL\Type\Definition\Type
        */
        $existingType = $this->schema->getType($typeName);
        if ($existingType) {
            $typeDef = $this->extendType($existingType);
            $this->typeDefCache[$typeName] = $typeDef;
            return $typeDef;
        }

        $typeNode = $this->typeDefinitionMap[$typeName];
        if ($typeNode) {
            $typeDef = $this->buildType($typeNode);
            $this->typeDefCache[$typeName] = $typeDef;
            return $typeDef;
        }
    }

    // Given a type's introspection result, construct the correct Type instance.
    private function extendType($type) {
        if ($type instanceof ObjectType) {
            return $this->extendObjectType($type);
        }
        if ($type instanceof InterfaceType) {
            return $this->extendInterfaceType($type);
        }
        if ($type instanceof UnionType) {
            return $this->extendUnionType($type);
        }
        return $type;
    }

    private function extendObjectType($type) {
        $name = $type->name;
        $extensionASTNodes = $type->extensionASTNodes;
        if ($this->typeExtensionsMap[$name]) {
            $extensionASTNodes = array_merge($extensionASTNodes, $this->typeExtensionsMap[$name]);
        }

        return new ObjectType([
            'name' => $name,
            'description' => $type->description,
            'interfaces' => function() use($type) {return $this->extendImplementedInterfaces($type);},
            'fields'  => function() use($type){
                return  $this->extendFieldMap($type);
            },
            'astNode' => $type->astNode,
            'extensionASTNodes' => $extensionASTNodes,
            'isTypeOf' => $type->isTypeOf,
        ]);
    }

    private function extendInterfaceType($type) {
        return new InterfaceType([
            'name' => $type->name,
            'description' => $type->description,
            'fields' => function() use($type) {return $this->extendFieldMap($type);},
            'astNode' => $type->astNode,
            'resolveType' => $type->resolveType,
        ]);
    }

    private function extendUnionType($type) {
        return new UnionType([
            'name' => $type->name,
            'description' => $type->description,
            'types' => Utils::map($type->getTypes(), function($typeNode) {
                return $this->getTypeFromDef($typeNode);
            }),
            'types' => $types,
            'astNode' => $type->astNode,
            'resolveType' => $type->resolveType,
        ]);
    }

    private function extendImplementedInterfaces($type) {
        if ($type instanceof ObjectType) {
            $interfaces = Utils::map($type->getInterfaces(), function ($item){
                return $this->getTypeFromDef($item);
            });
        }
        // If there are any extensions to the interfaces, apply those here.
        $extensions = $this->typeExtensionsMap[$type->name];
        if ($extensions) {
            foreach ($extensions as $extension) {
                foreach ($extension->definition->interfaces as $namedType) {
                    $interfaceName = $namedType->name->value;
                    foreach ($interfaces as $def) {
                        if($def->name === $interfaceName){
                            throw new Error("Type \"$type->name\" already implements \"$interfaceName\". It cannot also be implemented in this type extension.");
                        }
                    }
                    $interfaces[] = function() use($namedType) {return $this->getInterfaceTypeFromAST($namedType);};
                };
            };
        }
        return $interfaces;
    }

    private function extendFieldMap($type) {
        $newFieldMap = [];
        $oldFieldMap = $type->getFields();
        foreach ($oldFieldMap as $fieldName => $field) {
            /**
            * @var \GraphQL\Type\Definition\FieldDefinition $field
            */
            //TODO Make arguments here array ---------------------------------------------------------------------------------------------------------------------


            $args = Utils::keyMap($field->args, function ($arg) { return $arg->name;});

            $newFieldMap[$fieldName] = [
                'description' => $field->description,
                'deprecationReason' => $field->deprecationReason,
                'type' => $this->extendFieldType($field->getType()),
                'args' => $args,
                'astNode' => $field->astNode,
                'resolve' => $field->resolveFn,
            ];
        }


        // If there are any extensions to the fields, apply those here.
        $extensions = $this->typeExtensionsMap[$type->name];
        if ($extensions) {
            foreach ($extensions as $extension) {
                foreach ($extension->definition->fields as $field) {
                    $fieldName = $field->name->value;
                    if ($oldFieldMap[$fieldName]) {
                        throw new \ErrorException("Field \"$type->name\" -> \"$fieldName\" already exists in the schema. It cannot also be defined in this type extension.");
                    }
                    $newFieldMap[$fieldName] = [
                        'description' => $this->getDescription($field),
                        'type' => $this->buildOutputFieldType($field->type),
                        'args' => $this->buildInputValues($field->arguments),
                        'deprecationReason' => $this->getDeprecationReason($field),
                        'astNode' => $field,
                    ];
                };
            };
        }
        return $newFieldMap;
    }

    /**
     * Given an ast node, returns its string description based on a contiguous
     * block full-line of comments preceding it.
     */
    public function getDescription($node)
    {
        $loc = $node->loc;
        if (!$loc || !$loc->startToken) {
            return ;
        }
        $comments = [];
        $minSpaces = null;
        $token = $loc->startToken->prev;
        while (
            $token &&
            $token->kind === Token::COMMENT &&
            $token->next && $token->prev &&
            $token->line + 1 === $token->next->line &&
            $token->line !== $token->prev->line
        ) {
            $value = $token->value;
            $spaces = $this->leadingSpaces($value);
            if ($minSpaces === null || $spaces < $minSpaces) {
                $minSpaces = $spaces;
            }
            $comments[] = $value;
            $token = $token->prev;
        }
        return implode("\n", array_map(function($comment) use ($minSpaces) {
            return mb_substr(str_replace("\n", '', $comment), $minSpaces);
        }, array_reverse($comments)));
    }

    private function buildOutputFieldType($typeNode) {
        if ($typeNode->kind == NodeKind::LIST_TYPE) {
            return Type::listOf($this->buildWrappedType($typeNode->type));
        }
        if ($typeNode->kind === NodeKind::NON_NULL_TYPE) {
            $nullableType = $this->buildOutputFieldType($typeNode->type);
            Utils::invariant(!($nullableType instanceof NonNull), 'Must be nullable');
            return Type::NonNull($nullableType);
        }
        return $this->getOutputTypeFromAST($typeNode);
    }

    private function getOutputTypeFromAST($node) {
        $type = $this->getTypeFromAST($node);
        Utils::invariant(Type::isOutputType($type), 'Expected Input type.');
        return $type;
    }

    function getTypeFromAST($node) {
        $type = $this->_getNamedType($node->name->value);
        if (!$type) {
            throw new Error("Unknown type: \"$node->name->value\". Ensure that this type exists either in the original schema, or is added in a type definition.");
        }
        return $type;
    }

    private function getInterfaceTypeFromAST($node) {
        $type = $this->getTypeFromAST($node);
        Utils::invariant($type instanceof InterfaceType, 'Must be Interface type.');
        return $type;
    }

    private function extendFieldType($typeDef) {
        if ($typeDef instanceof ListOfType) {
            return (Type::listOf($this->extendFieldType($typeDef->getWrappedType(true))));
        }
        if ($typeDef instanceof NonNull) {
            return (Type::nonNull($this->extendFieldType($typeDef->getWrappedType(true))));
        }
        return $this->getTypeFromDef($typeDef);
    }

    private function buildInputValues($values) {
        return Utils::keyValMap(
            $values,
            function ($value) {
                return $value->name->value;
            },
            function($value) {
                $type = $this->buildInputFieldType($value->type);
                $config = [
                    'name' => $value->name->value,
                    'type' => $type,
                    'description' => $this->getDescription($value),
                    'astNode' => $value
                ];
                if (isset($value->defaultValue)) {
                    $config['defaultValue'] = AST::valueFromAST($value->defaultValue, $type);
                }
                return $config;
            }
        );
    }

    function buildInputFieldType($typeNode) {
        if ($typeNode->kind == NodeKind::LIST_TYPE) {
            return Type::listOf($this->buildInputFieldType($typeNode->type));
        }
        if ($typeNode->kind == NodeKind::NON_NULL_TYPE) {
            $nullableType = buildInputFieldType($typeNode->type);
            Utils::invariant(!($nullableType instanceof NonNull), 'Must be nullable');
            return Type::NonNull($nullableType);
        }
        return $this->getInputTypeFromAST($typeNode);
    }

    private function getInputTypeFromAST($node) {
        return Type::isInputType($this->getTypeFromAST($node));
    }

    private function getDeprecationReason($node) {
        $deprecated = Values::getDirectiveValues(Directive::deprecatedDirective(), $node);
        return isset($deprecated['reason']) ? $deprecated['reason'] : null;
    }

    private function buildType($typeNode) {
        switch ($typeNode->kind) {
            case NodeKind::OBJECT_TYPE_DEFINITION: return $this->buildObjectType($typeNode);
            case NodeKind::INTERFACE_TYPE_DEFINITION: return $this->buildInterfaceType($typeNode);
            case NodeKind::UNION_TYPE_DEFINITION: return $this->buildUnionType($typeNode);
            case NodeKind::SCALAR_TYPE_DEFINITION: return $this->buildScalarType($typeNode);
            case NodeKind::ENUM_TYPE_DEFINITION: return $this->buildEnumType($typeNode);
            case NodeKind::INPUT_OBJECT_TYPE_DEFINITION:
                return $this->buildInputObjectType($typeNode);
        }
        throw new Error('Unknown type kind ' + $typeNode->kind);
    }

    private function buildObjectType($typeNode) {
        return new ObjectType([
            'name' => $nodeType->name->value,
            'description' => $this->getDescription($typeNode),
            'interfaces' => function() use($typeNode) {return $this->buildImplementedInterfaces($typeNode);},
            'fields'  => function() use($typeNode){return  $this->buildFieldMap($typeNode);},
            'astNode' => $typeNode
            ]
        );
    }

    private function buildImplementedInterfaces($typeNode) {
        return  Utils::map($typeNode->getInterfaces(), function ($item){
            return $this->getInterfaceTypeFromAST($item);
        });
    }

    private function buildFieldMap($typeNode) {
        return Utils::keyValMap(
            $typeNode->fields,
            function ($fields){
                return $field->name->value;
            },
            function ($field) {
                return [
                    'type' => $this->buildOutputFieldType($field->type),
                    'description' => $this->getDescription($field),
                    'args' => $this->buildInputValues($field->arguments),
                    'deprecationReason' => $this->getDeprecationReason($field),
                    'astNode' => $field,
                ];
            }
        );
    }

    private function buildInterfaceType($typeNode) {
        return new InterfaceType([
            'name' => $typeNode->name->value,
            'description' => $this->getDescription($typeNode),
            'fields' => function() {return $this->buildFieldMap($typeNode);},
            'astNode' =>  $typeNode,
            'resolveType' => function() {
                                $this->cannotExecuteExtendedSchema();
                            }
        ]);
    }

    private function buildUnionType($typeNode) {
        return new UnionType([
            'name' =>  $typeNode->name->value,
            'description' => $this->getDescription($typeNode),
            'types' => Utils::map($typeNode->types, function ($typeNode){
                return $this->getObjectTypeFromAST($typeNode);
            }),
            'astNode' => $typeNode,
            'resolveType' => function (){
                $this->cannotExecuteExtendedSchema();
            }
        ]);
    }

    function getObjectTypeFromAST($node) {
        $type = $this->getTypeFromAST($node);
        Utils::invariant($type instanceof ObjectType, 'Must be Object type.');
        return $type;
    }

    private function buildScalarType($typeNode) {
        return new CustomScalarType([
            'name' => $typeNode->name->value,
            'description' => $this->getDescription($typeNode),
            'astNode' => $typeNode,
            'serialize' => function ($id) {
                return $id;
            },
            // Note: validation calls the parse functions to determine if a
            // literal value is correct. Returning null would cause use of custom
            // scalars to always fail validation. Returning false causes them to
            // always pass validation.
            'parseValue' => function() {
                return false;
            },
            'parseLiteral' => function() {
                return false;
            }
        ]);
    }

    private function buildEnumType($typeNode) {
        return new EnumType([
            'name' => $typeNode->name->value,
            'description' => $this->getDescription($typeNode),
            'values' => Utils::keyValMap(
                $typeNode->values,
                function($enumValue) {return $enumValue->name->value;},
                function ($enumValue) {return [
                    'description' => $this->getDescription($enumValue),
                    'deprecationReason' => $this->getDeprecationReason($enumValue),
                    'astNode' => $enumValue,
                    ];
                }
            ),
            'astNode' => $typeNode,
        ]);
    }

    private function buildInputObjectType($typeNode) {
        return new InputObjectType([
            'name' => $typeNode->name->value,
            'description' => $this->getDescription($typeNode),
            'fields' =>  function () {return $this->buildInputValues($typeNode->fields);},
            'astNode' => $typeNode,
        ]);
    }

    public function cannotExecuteExtendedSchema() {
        throw new Error(
            'Generated Schema cannot use Interface or Union types for execution.'
        );
    }

}