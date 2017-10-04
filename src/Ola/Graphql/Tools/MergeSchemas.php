<?php
namespace Ola\GraphQL\Tools;

use GraphQL\Type\Schema;
use GraphQL\Type\Introspection;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;


use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Visitor;
use GraphQL\Language\Parser;

use GraphQL\Utils\Utils;
use GraphQL\Utils\BuildSchema;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Definition\ScalarType;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\GraphQL;


use Ola\GraphQL\Tools\ExecutableSchema;
use Ola\GraphQL\Tools\ExtendSchema;
use Ola\GraphQL\Tools\TypeRegistry;

Class MergeInfo {
    private $typeRegistry;

    public function __construct($typeRegistry){
        $this->typeRegistry = $typeRegistry;
    }

    /**
    * @param mixed $type: 'query' | 'mutation'
    * @param mixed $fieldName
    * @param mixed $args
    * @param mixed $context
    * @param ResolveInfo $info
    */
    public function delegate ($operation, $fieldName, $args, $context, ResolveInfo $info) {
        $schema = $this->typeRegistry->getSchemaByField($operation, $fieldName);

        if (!$schema) {
            throw new \ErrorException("Cannot find subschema for root field \"$operation -> \"$fieldName\"");
        }
        $fragmentReplacements = $this->typeRegistry->fragmentReplacements;
        return $this->delegateToSchema($schema, $fragmentReplacements, $operation, $fieldName, $args, $context, $info);
    }

    /**
    * @param mixed $schema
    * @param mixed $fragmentReplacements
    */
    private function delegateToSchema($schema, $fragmentReplacements, $operation, $fieldName, $args, $context, ResolveInfo $info) { //Promise
        if ($operation == 'mutation') {
            $type = $schema->getMutationType();
        } else {
            $type = $schema->getQueryType();
        }

        if ($type) {
            /**
            * @var \GraphQL\Language\AST\DocumentNode
            */
            $graphqlDoc = $this->createDocument($schema, $fragmentReplacements, $type, $fieldName, $operation, $info->fieldNodes, $info->fragments, $info->operation->variableDefinitions);
            $operationDefinition = Utils::find($graphqlDoc->definitions, function($definition) {return $definition->kind == NodeKind::OPERATION_DEFINITION;});
            $variableValues = null;
            if (!empty($operationDefinition) && !empty($operationDefinition->variableDefinitions)) {
                $variableValues = Utils::map($operationDefinition->variableDefinitions, function ($definition) use($args, $info){
                    $key = $definition->variable->name->value;
                    $actualKey = $key;
                    if (Utils::startsWith($actualKey, '_')) {
                        $actualKey = substr($actualKey, 1);
                    }
                    $value = $args[$actualKey] || $args[$key] || $info->variableValues[$key];
                    return [$key, $value];
                });
            }
            DebugBreak('1@localhost');
            $result = GraphQL::executeQuery($schema, $graphqlDoc, $info->rootValue, $context, $variableValues);

            if (!empty($result->errors)) {
                $errorMessages = Utils::map($result->errors, function($error) { return $error->message;});
                $errorMessage = implode('\n', $errorMessages);
                throw new \ErrorException($errorMessage);
            } else {
                return $result->data[$fieldName];
            }
        }

        throw new \ErrorException('Could not forward to merged schema');
    }

    private function createDocument($schema, $fragmentReplacements, $type, $rootFieldName, $operation, $selections, $fragments, $variableDefinitions = []) {
        $rootField = $type->getFields()[$rootFieldName];
        $newVariables = [];
        $rootSelectionSet = new SelectionSetNode([
            'kind' => NodeKind::SELECTION_SET,
            'selections' => Utils::map($selections, function($selection) use($newVariables, $rootFieldName, $rootField) {
                if ($selection->kind == NodeKind::FIELD) {
                    list($newSelection, $variables ) = $this->processRootField($selection, $rootFieldName, $rootField);
                    $newVariables = array_merge($newVariables, $variables);
                    return $newSelection;
                } else {
                    return $selection;
                }
            })
        ]);

        $newVariableDefinitions = Utils::map($newVariables, function($arg, $variable) use($rootField) {
            $argDef = Utils::find($rootField->args, function($rootArg) use ($arg) {return $rootArg->name == $arg;});
            if (!$argDef) {
                throw new \ErrorException('Unexpected missing arg');
            }
            $typeName = $this->typeToAst($argDef->type);
            return new VariableNode([
                'kind' => NodeKind::VARIABLE_DEFINITION,
                'variable' => new VariableNode([
                    'kind' => NodeKind::VARIABLE,
                    'name' => new NameNode([
                        'kind' => NodeKind::NAME,
                        'value' => $variable,
                    ]),
                ]),
                'type' => $typeName,
            ]);
        });

        list($selectionSet, $processedFragments, $usedVariables) = $this->filterSelectionSetDeep($schema, $fragmentReplacements, $type, $rootSelectionSet, $fragments);

        if(!empty($variableDefinitions)){
            $variableDefinitions = array_filter($variableDefinitions, function($variableDefinition) use($usedVariables){
                return in_array($usedVariables, $variableDefinition->variable->name->value);

            });
        }

        $operationDefinition = new OperationDefinitionNode([
            'kind' => NodeKind::OPERATION_DEFINITION,
            'operation' => $operation,
            'variableDefinitions' => !empty($variableDefinitions)? array_merge($variableDefinitions, $newVariableDefinitions):$newVariableDefinitions,
            'selectionSet' => $selectionSet
        ]);

        $newDoc = new DocumentNode([
            'kind' => NodeKind::DOCUMENT,
            'definitions' => !empty($processedFragments)? [$operationDefinition, $processedFragments]:[$operationDefinition]
        ]);
        return $newDoc;
    }

    /**
    * @param $fragmentReplacements - typeName: string, fieldName: string
    * @param $fragments
    * @return mixed : selectionSet, fragments, usedVariables
    */
    private function filterSelectionSetDeep($schema, $fragmentReplacements, $type, $selectionSet, $fragments) {
        $validFragments = [];
        foreach ($fragments as $fragmentName => $fragment) {
            $typeName = $fragment->typeCondition->name->value;
            $innerType = $schema->getType($typeName);
            if ($innerType) {
                $validFragments[] = $fragment->name->value;
            }
        };
        list($newSelectionSet, $remainingFragments, $usedVariables) = $this->filterSelectionSet($schema, $fragmentReplacements, $type, $selectionSet, $validFragments);

        $newFragments = [];
        // (XXX): So this will break if we have a fragment that only has link fields
        $haveRemainingFragments = count($remainingFragments);
        while ($haveRemainingFragments) {
            $name = array_pop($remainingFragments);
            if ($newFragments[$name]) {
                continue;
            } else {
                $nextFragment = $fragments[$name];
                if (!$name) {
                    throw new \ErrorException("Could not find fragment \"$name\"");
                }
                $typeName = $nextFragment->typeCondition->name->value;
                $innerType = $schema->getType($typeName);
                if (!$innerType) {
                    continue;
                }
                list($fragmentSelectionSet, $fragmentUsedFragments, $fragmentUsedVariables) = $this->filterSelectionSet($schema, $fragmentReplacements, $innerType, $nextFragment->selectionSet, $validFragments);
                $remainingFragments = array_merge($remainingFragments, $fragmentUsedFragments);
                $usedVariables = array_merge($usedVariables, $fragmentUsedVariables);
                $newFragments[$name] = [
                    'kind' => NodeKind::FRAGMENT_DEFINITION,
                    'name' => new NameNode([
                        'kind' => NodeKind::NAME,
                        'value' => $name,
                    ]),
                    'typeCondition' => $nextFragment->typeCondition,
                    'selectionSet' => $fragmentSelectionSet,
                ];
            }
            $haveRemainingFragments = count($remainingFragments);
        }
        $newFragmentValues = Utils::map($newFragments,  function($name) use($newFragments) {
            return new FragmentDefinitionNode($newFragments[$name]);
        });
        return [
            $newSelectionSet,
            $newFragmentValues,
            $usedVariables,
        ];
    }

    /**
    * returns : SelectionSetNode selectionSet, Array usedFragments, Array usedVariables
    *
    * @param mixed $type
    */
    private function filterSelectionSet($schema, $fragmentReplacements, $type, $selectionSet, $validFragments) {
        $usedFragments = [];
        $usedVariables = [];
        $typeStack = [$type];
        $filteredSelectionSet = new SelectionSetNode(Visitor::visit($selectionSet, [
            NodeKind::FIELD => [
                'enter' => function($node) use($typeStack, $fragmentReplacements) {
                    $parentType = $this->resolveType($typeStack[count($typeStack) - 1]);
                    if ($parentType instanceof NonNull || $parentType instanceof ListOfType) {
                        $parentType = $parentType->getWrappedType(true);//if error stdObject look for ->ofType
                    }
                    if ($parentType instanceof ObjectType || $parentType instanceof InterfaceType) {
                        $fields = $parentType->getFields();
                        $field = ($node->name->value == '__typename') ? Introspection::TypeNameMetaFieldDef() : $fields[$node->name->value];
                        if (!$field) {
                            if(!empty($fragmentReplacements[$parentType->name])){
                                $fragment = $fragmentReplacements[$parentType->name][$node->name->value];
                            }
                            if ($fragment) return $fragment;
                        } else {
                            return null;
                        }
                    } else {
                        $typeStack[] = $field->type;
                    }
                },
                'leave' => function () use($typeStack){
                    array_pop($typeStack);
                },
            ],
            NodeKind::SELECTION_SET => function ($node) use($typeStack) {
                $parentType = $this->resolveType($typeStack[count($typeStack) - 1]);
                if ($parentType instanceof InterfaceType || $parentType instanceof UnionType) {
                    $node->selections[] = new FieldNode([
                                                        'kind' =>  NodeKind::FIELD,
                                                        'name' => new NameNode([
                                                            'kind' => NodeKind::NAME,
                                                            'value' => '__typename',
                                                        ]),
                    ]);
                    return $node;
                }
            },
            NodeKind::FRAGMENT_SPREAD => function($node) use($validFragments, $usedFragments) {
                if (in_array($validFragments, $node->name->value)) {
                    $usedFragments[] = $node->name->value;
                } else {
                    return null;
                }
            },
            NodeKind::INLINE_FRAGMENT => [
                'enter' => function($node) use($typeStack){
                    if ($node->typeCondition) {
                        $innerType = $schema->getType($node->typeCondition->name->value);
                        if ($innerType) {
                            $typeStack[] = $innerType;
                        } else {
                            return null;
                        }
                    }
                },
                'leave' => function ($node) use($typeStack) {
                    if ($node->typeCondition) {
                        $innerType = $schema->getType($node->typeCondition->name->value);
                        if ($innerType) {
                            array_pop($typeStack);
                        } else {
                            return null;
                        }
                    }
                },
            ],
            NodeKind::VARIABLE => function($node) use($usedVariables) {
                $usedVariables[] = $node->name->value;
            },
        ]));


        return [$filteredSelectionSet, $usedFragments, $usedVariables];
    }

    private function resolveType($type) {
        $lastType = $type;
        while ($lastType instanceof NonNull || $lastType instanceof ListOfType) {
            $lastType = $lastType->getWrappedType(true);//if error stdObject look for ->ofType
        }
        return $lastType;
    }

    private function typeToAst($type) {
        if ($type instanceof NonNull) {
            $innerType = $this->typeToAst($type->getWrappedType(true));//if error stdObject look for ->ofType
            if ($innerType->kind == NodeKind::LIST_TYPE || $innerType->kind == NodeKind::NAMED_TYPE) {
                return new NonNullTypeNode([
                    'kind' => NodeKind::NON_NULL_TYPE,
                    'type' => $innerType,
                ]);
            } else {
                throw new \ErrorException('Incorrent inner non-null type');
            }
        } else if ($type instanceof ListOfType) {
            return new ListTypeNode([
                'kind' => NodeKind::LIST_TYPE,
                'type' => $this->typeToAst($type->getWrappedType(true)),//if error stdObject look for ->ofType
            ]);
        } else {
            return new NamedTypeNode ([
                'kind' => NodeKind::NAMED_TYPE,
                'name' => new NameNode([
                    'kind' => NodeKind::NAME,
                    'value' => $type->toString(),
                ]),
            ]);
        }
    }

    /**
    * returnd array with FieldNode selection and variables array
    */
    private function processRootField($selection, $rootFieldName, $rootField) {
        $existingArguments = $selection->arguments ?: [];
        $existingArgumentNames = Utils::map($existingArguments,function ($arg){return $arg->name->value;});
        $missingArgumentNames = array_diff(Utils::map($rootField->args, function($arg) {return $arg->name;}), $existingArgumentNames);
        $variables = [];
        $missingArguments = Utils::map($missingArgumentNames, function($name) use($variables){
            $variableName = "_".$name;
            $variables[] = [
                'arg' => $name,
                'variable' => $variableName,
            ];
            return new ArgumentNode([
                'kind' => NodeKind::ARGUMENT,
                'name' => new NameNode([
                    'kind' => NodeKind::NAME,
                    'value' => $name,
                ]),
                'value' => new VariableNode([
                    'kind' => NodeKind::VARIABLE,
                    'name' => new NameNode([
                        'kind' => NodeKind::NAME,
                        'value' => $variableName,
                    ])
                ])
            ]);
        });

        $arguments = $existingArguments->merge($missingArguments);

        return [
            new FieldNode([
                'kind' => NodeKind::FIELD,
                'alias' => null,
                'arguments' => $arguments,
                'selectionSet' => $selection->selectionSet,
                'name' => new NameNode([
                    'kind' => NodeKind::NAME,
                    'value' => $rootFieldName
                ]),
            ]),
            $variables
        ];
    }



}
//End class MergeInfo

class MergeSchemas
{

    private $schemas = [];
    private $onTypeConflict;
    private $resolvers;

    public static function mergeSchemas($schemas, $resolvers, $onTypeConflict = null){
        $merger = new self($schemas, $resolvers, $onTypeConflict);
        return $merger->merge($schemas, $resolvers, $onTypeConflict);
    }

    public function __construct($schemas){
        if(!is_array($schemas)){
            throw new \Exception("Input schemas must be array of \"GraphQL\\Type\\Schema\" or string schema definitions");
        }

        $this->schemas = $schemas;
        $this->onTypeConflict = $onTypeConflict;
        $this->resolvers = $resolvers;
    }

    public function merge($schemas, $resolvers, $onTypeConflict = null){

        if(!$onTypeConflict){
            $onTypeConflict = function($left, $right)  {
                                return $left;
            };
        }


        $queryFields = [];
        $mutationFields = [];

        $typeRegistry = new TypeRegistry();
        $mergeInfo = new MergeInfo($typeRegistry);

        $actualSchemas = [];
        $extensions = [];
        $fullResolvers = [];

        foreach ($schemas as $key => $schema) {
            if ($schema instanceof Schema) {
                $actualSchemas[] = $schema;
            } else if (is_string($schema)) {
                $parsedSchemaDocument = Parser::parse($schema);
                try {
                    $actualSchema = BuildSchema::buildAST($parsedSchemaDocument);
                    $actualSchemas[] = $actualSchema;
                } catch (\Exception $e) {
                    // Could not create a schema from parsed string, will use extensions
                }
                $parsedSchemaDocument = ExecutableSchema::extractExtensionDefinitions($parsedSchemaDocument);
                if (count($parsedSchemaDocument->definitions)) {
                    $extensions[] = $parsedSchemaDocument;
                }
            }
        }

        foreach ($actualSchemas as $key => $schema) {
            $typeRegistry->addSchema($schema);
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();
            foreach ($schema->getTypeMap() as $typeName => $type) {
                if (Type::isNamedType($type) && substr(Type::getNamedType($type)->name, 0, 2) !== '__' &&  $type !== $queryType && $type !== $mutationType) {
                    $newType = null;
                    if (Type::isCompositeType($type) || $type instanceof InputObjectType) {
                        $newType = $this->recreateCompositeType($schema, $type, $typeRegistry);
                    } else {
                        $newType = Type::getNamedType($type);
                    }
                    $typeRegistry->addType($newType->name, $newType, $onTypeConflict);
                }
            };
        }

        // This is not a bug/oversight, we iterate twice cause we want to first
        // resolve all types and then force the type thunks
        foreach ($actualSchemas as $key => $schema) {
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();

            foreach ($queryType->getFields() as $name => $val) {
                if (!$fullResolvers['Query']) {
                    $fullResolvers['Query'] = [];
                }
                $fullResolvers['Query'][$name] = $this->createDelegatingResolver($mergeInfo, 'query', $name);
            }

            $queryFields = array_merge($queryFields, $this->fieldMapToFieldConfigMap($queryType->getFields(), $typeRegistry));
            if ($mutationType) {
                if (!$fullResolvers['Mutation']) {
                    $fullResolvers['Mutation'] = [];
                }
                foreach ($mutationType->getFields() as $name => $val) {
                    $fullResolvers['Mutation'][$name] = $this->createDelegatingResolver($mergeInfo, 'mutation', $name);
                }

                $mutationFields = array_merge($mutationFields, $this->fieldMapToFieldConfigMap($mutationType->getFields(), $typeRegistry));
            }
        }

        $passedResolvers = [];

        if(is_callable($resolvers)) $passedResolvers = call_user_func($resolvers, $mergeInfo);

        if(count($passedResolvers))
        foreach ($passedResolvers as $typeName => $type) {
            if ($type instanceof ScalarType) {
                break;
            }
            foreach ($type as $fieldName => $field) {
                if ($field['fragment']) {
                    $typeRegistry->addFragment($typeName, $fieldName, $field['fragment']);
                }
            };
        };

        $fullResolvers = $this->mergeDeep($fullResolvers, $passedResolvers);

        $query = new ObjectType([
            'name' => 'Query',
            'fields' => $queryFields
        ]);

        $mutation = null;
        if (!empty($mutationFields)) {
            $mutation = new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields,
            ]);
        }

        $mergedSchema = new Schema([
            'query' => $query,
            'mutation' => $mutation,
            'types' => $typeRegistry->getAllTypes(),
        ]);

        foreach ($extensions as $key => $extension) {
            $mergedSchema = ExtendSchema::extend($mergedSchema, $extension);
        };

        ExecutableSchema::addResolveFunctionsToSchema($mergedSchema, $fullResolvers);

        return $mergedSchema;
    }

    private function mergeDeep($target, $source) {
        $output = $target;
        if (is_array($target) && is_array($source)) {
            foreach ($source as $key => $src) {
                if(is_array($src)){
                    if(empty($target[$key])){
                        $output[$key] = $src;
                    }else{
                        $output[$key] = $this->mergeDeep($target[$key], $source[$key]);
                    }
                }else{
                    $output[$key] = $src;
                }
            };
        }
        return $output;
    }

    private function createDelegatingResolver($mergeInfo, $operation, $fieldName) {
        return function($root, $args, $context, $info) use($mergeInfo, $operation, $fieldName) {
            return $mergeInfo->delegate($operation, $fieldName, $args, $context, $info);
        };
    }

    private function recreateCompositeType($schema, $type, $registry) {
        if ($type instanceof ObjectType) {
            $fields = $type->getFields();
            $interfaces = $type->getInterfaces();
            return new ObjectType([
                'name' => $type->name,
                'description' => $type->description,
                'isTypeOf' => $type->isTypeOf,
                'fields' => function() use($fields, $registry) {
                                return $this->fieldMapToFieldConfigMap($fields, $registry);
                },
                'interfaces' => function() use($interfaces, $registry) {
                                    return Utils::map($interfaces, function($iface) use($registry) {
                                        return $registry->resolveType($iface);
                                    });
                }
            ]);
        } elseif ($type instanceof InterfaceType) {
            $fields = $type->getFields();
            return new InterfaceType([
                'name' => $type->name,
                'description' => $type->description,
                'fields' => function() use($fields, $registry) {
                                return $this->fieldMapToFieldConfigMap($fields, $registry);
                },
                'resolveType' => function($parent, $context, $info) {
                                    return $this->resolveFromParentTypename($parent, $info->schema);
                }
            ]);
        } elseif ($type instanceof UnionType) {
            return new UnionType([
                'name' => $type->name,
                'description' => $type->description,
                'types' => function() use($type, $registry) {
                                return Utils::map($type->getTypes(), function($unionMember) use($registry) {
                                    return $registry->resolveType($unionMember);
                                });
                },
                'resolveType' => function($parent, $context, $info) {
                                    return $this->resolveFromParentTypename($parent, $info->schema);
                }
            ]);
        } elseif ($type instanceof InputObjectType) {
            return new InputObjectType([
                'name' => $type->name,
                'description' => $type->description,
                'fields' => function () use($type, $registry) {
                                return $this->inputFieldMapToFieldConfigMap($type->getFields(), $registry);
                }
            ]);
        } else {
            throw new \ErrorException("Invalid type \"$type\"");
        }
    }

    private function fieldMapToFieldConfigMap($fields, $registry) {
        $result = [];
        foreach ($fields as $name => $field) {
            $result[$name] = $this->fieldToFieldConfig($field, $registry);
        }
        return $result;
    }

    private function fieldToFieldConfig(\GraphQL\Type\Definition\FieldDefinition $field, \Ola\GraphQL\Tools\TypeRegistry $registry) {
        return [
            'type' => $registry->resolveType($field->getType()),
            'args' => $this->argsToFieldConfigArgumentMap($field->args, $registry),
            'description' => $field->description,
            'deprecationReason' => $field->deprecationReason,
        ];
    }

    private function argsToFieldConfigArgumentMap($args, $registry) {
        $result = [];
        foreach ($args as $key => $arg) {
            $result[$arg->name] = $this->argumentToArgumentConfig($arg, $registry);
        }
        return $result;
    }

    private function argumentToArgumentConfig(\GraphQL\Type\Definition\FieldArgument $argument, \Ola\GraphQL\Tools\TypeRegistry $registry) {
        return [
            'name' => $argument->name,
            'type' => $registry->resolveType($argument->getType()),
            'defaultValue' => $argument->defaultValue,
            'description' => $argument->description,
            'astNode' => $argument
        ];
    }

    public static function resolveFromParentTypename($parent, $schema) {
        $parentTypename = $parent['__typename'];
        if (!$parentTypename) {
            throw new \ErrorException("Did not fetch typename for object, unable to resolve interface.");
        }
        $resolvedType = $schema->getType($parentTypename);

        if (!($resolvedType instanceof ObjectType)) {
            throw new \ErrorException("__typename did not match an object type: " . $parentTypename);
        }
        return $resolvedType;
    }

    private function inputFieldMapToFieldConfigMap($fields, $registry) {
        return Utils::mapValues($fields, function($field) use($registry) {
                                            return $this->inputFieldToFieldConfig($field, $registry);
        });
    }

    function inputFieldToFieldConfig($field, $registry) {
        return [
            'type' => $registry->resolveType($field->type),
            'description' => $field->description,
        ];
    }
}
?>
