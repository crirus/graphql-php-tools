<?php
namespace Ola\GraphQL\Tools;

use GraphQL\Language\Printer;
use GraphQL\Utils\SchemaPrinter;

use GraphQL\Type\Schema;
use GraphQL\Utils\Utils;


class RoutingResolvers
{
    //makeRemoteExecutableSchema
    public static function makeRemoteExecutableSchema(Schema $schema, $endpointURL /*TODO add a Fetcher here instead curl dependant code*/){
        $queries = $schema->getQueryType()->getFields();
        foreach ($queries as $key => $val) {
            $queryResolvers[$key] =  RoutingResolvers::createResolver($endpointURL);
        }

        $mutationResolvers = [];
        $mutationType = $schema->getMutationType();
        if ($mutationType) {
            $mutations = $mutationType->getFields();
            foreach ($mutations as $key => $val) {
                $mutationResolvers[$key] =  RoutingResolvers::createResolver($endpointURL);
            }
        }

        $resolvers = [
            $schema->getQueryType()->name => $queryResolvers,
        ];

        if (!empty($mutationResolvers)) {
            $resolvers[$schema->getMutationType()->name] = $mutationResolvers;
        }


        $typeMap = $schema->getTypeMap();
        foreach ($typeMap as $name => $type) {
            $types[$name] = $type;
        }

        foreach ($types as $type) {
            if ($type instanceof InterfaceType || $type instanceof UnionType) {
                $resolvers[$type->name] = function ($parent, $context, $info) {
                    return MergeSchemas::resolveFromParentTypename($parent, $info->schema);

                };
            }elseif($type instanceof ScalarType) {
                if (!($type == IDType || $type == StringType || $type == FloatType || $type == BooleanType || $type == IntType)) {
                    $resolvers[$type->name] = $this->createPassThroughScalar($type);
                }
            }

        }

        $typeDefs = SchemaPrinter::doPrint($schema);
        return ExecutableSchema::makeExecutableSchema($typeDefs, $resolvers);
    }

    private static function createResolver($endpointURL) {
        return function ($root, $args, $context, $info) use ($endpointURL) {
            $operation = Printer::doPrint($info->operation);
            $fragments = Utils::map($info->fragments, function ($value, $key){
                return Printer::doPrint($value);
            });
            $fragments = implode("\n",(array)$fragments);

            $query = $operation."\n".$fragments;


            $json = json_encode(['query' => $query, 'variables' => $info->variableValues]);

            $chObj = curl_init();
            curl_setopt($chObj, CURLOPT_URL, $endpointURL);
            curl_setopt($chObj, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chObj, CURLOPT_POST, 1);
            curl_setopt($chObj, CURLOPT_HEADER, true);
            curl_setopt($chObj, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($chObj, CURLINFO_HEADER_OUT, true);
            curl_setopt($chObj, CURLOPT_POSTFIELDS, $json);

            $response = curl_exec($chObj);
            //TODO make sure micro server api returns json alone
            $response = array_pop(explode("\r\n\r\n",$response));
            $result = json_decode($response, true);

            $fieldName = $info->fieldNodes[0]->alias ? $info->fieldNodes[0]->alias->value : $info->fieldName;

            if ($result['errors'] || !$result['data'][$fieldName]) {
                throw new \ErrorException("Error reading remote graphql for field $fieldName");
            } else {
                return $result['data'][$fieldName];
            }
        };
    }

    function createPassThroughScalar($name, $description) {
        return new ScalarType([
            'name' => $name,
            'description' => $description,
            'serialize' => function($value) {
                return value;
            },
            'parseValue' => function($value) {
                return $value;
            },
            'parseLiteral' => function($ast) {
                return $this->parseLiteral($ast);
            },
        ]);
    }

    function parseLiteral($ast) {
        //TODO verify
        switch ($ast->kind) {
            case NodeKind::STRING:
            case NodeKind::BOOLEAN:
                return $ast->value;
                break;
            case NodeKind::INT:
            case NodeKind::FLOAT:
                return floatval($ast->value);
                break;
            case NodeKind::OBJECT:
                $value = [];
                foreach ($ast->fields as $field) {
                    $value[$field->name->value] = $this->parseLiteral($field->value);
                }
                return $value;
            case NodeKind::LST:
                return Utils::map($ast->values, parseLiteral);
            default:
                return null;
        }
    }


}