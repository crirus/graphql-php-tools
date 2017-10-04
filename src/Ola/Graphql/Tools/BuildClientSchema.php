<?php
namespace Ola\GraphQL\Tools;

use GraphQL\Error\Error;
use GraphQL\Executor\Values;

use GraphQL\Language\Parser;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\Printer;


use GraphQL\Type\Schema;
use GraphQL\Type\Introspection;
use GraphQL\Type\TypeKind;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
//use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;

use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;





/**
 * Build instance of `GraphQL\Type\Schema` from  Introspection result of a Client Schema
*/
class BuildClientSchema {

    private $kinds = [];
    private $typeDefCache = [];
    private $typeIntrospectionMap = [];
    private $types = [];
    private $introspection;

    public static function build($introspection) {
        $builder = new self($introspection);
        return $builder->buildClientSchema();
    }

    public function __construct($introspection) {
        $this->introspection = $introspection;
    }

    private function buildClientSchema(){

        $schemaIntrospection = $this->introspection->__schema;

        $this->typeIntrospectionMap = Utils::keyMap($schemaIntrospection->types, function($type){
            return $type->name;
        });

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

        // Iterate through all types, getting the type definition for each, ensuring that any type not directly referenced by a field will get created.
        $types = Utils::map($schemaIntrospection->types, function($typeIntrospection){
            return $this->getNamedType($typeIntrospection->name);
        });

        // Get the root Query, Mutation, and Subscription types.
        $queryType = $this->getObjectType($schemaIntrospection->queryType);

        $mutationType = $schemaIntrospection->mutationType ? $this->getObjectType($schemaIntrospection->mutationType) : null;

        $subscriptionType = $schemaIntrospection->subscriptionType ? $this->getObjectType($schemaIntrospection->subscriptionType) : null;

        // Get the directives supported by Introspection, assuming empty-set if directives were not queried for.
        $directives = $schemaIntrospection->directives ?
            Utils::map(
                $schemaIntrospection->directives,
                function ($directive){
                    return $this->buildDirective($directive);
                }) :
            [];

        return new Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
            'subscription' => $subscriptionType,
            'types' => $types,
            'directives' => $directives
        ]);

    }

    /**
    * Given a type's introspection result, construct the correct GraphQLType instance.
    *
    */
    private function buildType($type){
        switch (Utils::getTypeKindLiteral($type->kind)) {
            case TypeKind::SCALAR:
                return $this->buildScalarDef($type);
            case TypeKind::OBJECT:
                return $this->buildObjectDef($type);
            case TypeKind::INTERFACE_KIND:
                return $this->buildInterfaceDef($type);
            case TypeKind::UNION:
                return $this->buildUnionDef($type);
            case TypeKind::ENUM:
                return $this->buildEnumDef($type);
            case TypeKind::INPUT_OBJECT:
                return $this->buildInputObjectDef($type);
            default:
                throw new \ErrorException("Invalid or incomplete schema, unknown kind \"$type->kind\". Ensure  that a full introspection query is used in order to build a client schema.");
        }
    }

    private function buildScalarDef($scalarIntrospection) {
        $tp = new CustomScalarType([
            'name' => $scalarIntrospection->name,
            'description' => $scalarIntrospection->description,
            'serialize' => function($id){
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
            },
        ]);
        return $tp;
    }

    private function buildObjectDef($objectIntrospection) {
        return new ObjectType([
            'name' => $objectIntrospection->name,
            'description' => $objectIntrospection->description,
            'interfaces' => Utils::map($objectIntrospection->interfaces, function ($typeRef){
                return $this->getInterfaceType($typeRef);
            }),
            'fields'  => function () use ($objectIntrospection) {
                return $this->buildFieldDefMap($objectIntrospection);
            }
        ]);
    }

    private function buildInterfaceDef($interfaceIntrospection) {
        return new InterfaceType([
            'name' => $interfaceIntrospection->name,
            'description' => $interfaceIntrospection->description,
            'fields' => function () use($interfaceIntrospection) {
                $ret = $this->buildFieldDefMap($interfaceIntrospection);
                return $ret;
            },
            'resolveType' => function() {
                return $this->cannotExecuteClientSchema();
            }
        ]);
    }

    private function buildEnumDef($enumIntrospection) {
        return new EnumType([
            'name' => $enumIntrospection->name,
            'description' => $enumIntrospection->description,
            'values' =>  Utils::keyValMap(
                $enumIntrospection->enumValues,
                function ($valueIntrospection){
                    return $valueIntrospection->name;
                },
                function ($valueIntrospection){
                    return [
                        'description' => $fieldIntrospection->description,
                        'deprecationReason' => $fieldIntrospection->deprecationReason
                    ];
                }
            )
        ]);
    }

    private function buildInputObjectDef($inputObjectIntrospection) {
        return new InputObjectType([
            'name' => $inputObjectIntrospection->name,
            'description' => $inputObjectIntrospection->description,
            'fields' => function() use($inputObjectIntrospection){
                return $this->buildInputValueDefMap($inputObjectIntrospection->inputFields);
            }
        ]);
    }

    private function buildUnionDef($unionIntrospection) {
        return new UnionType([
            'name' => $unionIntrospection->name,
            'description' => $unionIntrospection->description,
            'types' => Utils::map($unionIntrospection->possibleTypes, function ($typeRef){
                return $this->getObjectType($typeRef);
            }) ,
            'resolveType' => $this->cannotExecuteClientSchema(),
        ]);
    }

    private function buildFieldDefMap($typeIntrospection) {
        return Utils::keyValMap(
            $typeIntrospection->fields,
            function ($fieldIntrospection){
                return $fieldIntrospection->name;
            },
            function ($fieldIntrospection){
                return [
                    'description' => $fieldIntrospection->description,
                    'deprecationReason' => $fieldIntrospection->deprecationReason,
                    'type' => $this->getOutputType($fieldIntrospection->type),
                    'args' => $this->buildInputValueDefMap($fieldIntrospection->args),
                ];
            }
        );
    }

    private function buildInputValueDefMap($inputValueIntrospection) {
        return Utils::keyValMap(
            $inputValueIntrospection,
            function ($inputValue){
                return $inputValue->name;
            },
            function ($inputValue) {
                $type = $this->getInputType($inputValue->type);
                $defaultValue = $inputValue->defaultValue ? AST::valueFromAST(Parser::parseValue($inputValue->defaultValue), $type) : null;
                $inputConfig = [
                    'name' => $inputValue->name,
                    'description' => $inputValue->description,
                    'type' => $type,
                    'astNode' => $inputValue
                ];
                if($defaultValue){
                    $inputConfig['defaultValue'] = $defaultValue;
                }
                return $inputConfig;
            }
        );
    }

    private function getInterfaceType($typeRef) {
        $type = $this->getType($typeRef);
        Utils::invariant($type, 'Introspection must provide interface type for interfaces.');
        return $type;
    }

    private function getOutputType($typeRef) {
        $type = $this->getType($typeRef);
        Utils::invariant(Type::isOutputType($type), 'Introspection must provide output type for fields.');
        return $type;
    }

    /**
    * Given a type reference in introspection, return the GraphQLType instance, preferring cached instances before building new instances.
    *
    */
    private function getType($typeRef) {
        if (Utils::getTypeKindLiteral($typeRef->kind) === TypeKind::LIST_KIND) {
            $itemRef = $typeRef->ofType;
            if (!$itemRef) {
                throw new \ErrorException('Decorated type deeper than introspection query.');
            }
            return Type::listOf($this->getType($itemRef));
        }
        if (Utils::getTypeKindLiteral($typeRef->kind) === TypeKind::NON_NULL) {
            $nullableRef = $typeRef->ofType;
            if (!$nullableRef) {
                throw new Error('Decorated type deeper than introspection query.');
            }
            $nullableType = $this->getType($nullableRef);
            Utils::invariant(!($nullableType  instanceof NonNull), 'No nesting nonnull.');
            return Type::nonNull($nullableType);
        }
        return $this->getNamedType($typeRef->name);
    }

    private function getNamedType($typeName){
        if ($this->typeDefCache[$typeName]) {
          return $this->typeDefCache[$typeName];
        }
        $typeIntrospection = $this->typeIntrospectionMap[$typeName];
        if (!$typeIntrospection) {
          throw new \ErrorException('Invalid or incomplete schema, unknown type: '.$typeName.'. Ensure  that a full introspection query is used in order to build a client schema.');
        }
        $typeDef = $this->buildType($typeIntrospection);
        $this->typeDefCache[$typeName] = $typeDef;
        return $typeDef;
    }

    private function getInputType($typeRef) {
        $type = $this->getType($typeRef);
        Utils::invariant(Type::isInputType($type), 'Introspection must provide input type for arguments %s.', var_export($type, 1));
        return $type;
    }

    private function getObjectType($typeRef) {
        $type = $this->getType($typeRef);
        Utils::invariant($type instanceof ObjectType, 'Introspection must provide object type for possibleTypes.');
        return $type;
    }

    private function buildDirective($directiveIntrospection) {
        // Support deprecated `on****` fields for building `locations`, as this is used by GraphiQL which may need to support outdated servers.
        $locations = $directiveIntrospection->locations ? $directiveIntrospection->locations :  [];
        $onField = !$directiveIntrospection->onField ? [] : [DirectiveLocation::FIELD];
        $onOperation = !$directiveIntrospection->onOperation ? [] : [DirectiveLocation::QUERY, DirectiveLocation::MUTATION, DirectiveLocation::SUBSCRIPTION];
        $onFragment = !$directiveIntrospection->onFragment ? [] : [DirectiveLocation::FRAGMENT_DEFINITION, DirectiveLocation::FRAGMENT_SPREAD, DirectiveLocation::INLINE_FRAGMENT];

        $locations = array_merge($locations, $onField, $onOperation, $onFragment);

        return new Directive([
            'name' => $directiveIntrospection->name,
            'description' => $directiveIntrospection->description,
            $locations,
            'args' => $this->buildInputValueDefMap($directiveIntrospection->args),
        ]);
    }

    private function cannotExecuteClientSchema(){
        throw new \ErrorException('Client Schema cannot use Interface or Union types for execution.');
    }
}