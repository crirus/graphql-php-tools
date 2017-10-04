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
use GraphQL\Type\Definition\ScalarType;


use GraphQL\Type\Introspection;
use GraphQL\Type\TypeKind;

use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\Utils;

use Ola\GraphQL\Tools\ExtendSchema;

class SchemaError extends \ErrorException {
    public $message;
    public function __construct($message) {
        parent::__construct($message);
        $this->message = $message;
    }
}

class TypeError extends \ErrorException {
    public $message;
    public function __construct($message) {
        parent::__construct($message);
        $this->message = $message;
    }
}


/**
 * Build instance of `GraphQL\Type\Schema` from  Introspection result of a Client Schema
*/
class ExecutableSchema {

    /**
    * put your comment there...
    *
    * @param mixed $typeDefs
    * @param mixed $resolvers
    * @param mixed $connectors
    * @param mixed $logger
    * @param mixed $allowUndefinedInResolve
    * @param mixed $resolverValidationOptions
    */
    public static function makeExecutableSchema($typeDefs, $resolvers = [], $connectors = null, $logger = null, $allowUndefinedInResolve = true, $resolverValidationOptions = []) {
        $execSchema = new self();
        return $execSchema->makeEx($typeDefs, $resolvers, $connectors, $logger, $allowUndefinedInResolve, $resolverValidationOptions);
    }

    private function makeEx($typeDefs, $resolvers, $connectors, $logger, $allowUndefinedInResolve, $resolverValidationOptions) {
        $schema = $this->_generateSchema($typeDefs, $resolvers, $logger, $allowUndefinedInResolve, $resolverValidationOptions);

        if (is_callable($resolvers['__schema'] )) {
            $this->addSchemaLevelResolveFunction($schema, $resolvers['__schema']);
        }
        if ($connectors) {
            // connectors are optional, at least for now. That means you can just import them in the resolve
            // function if you want.
            //$this->attachConnectorsToContext($schema, $connectors);
        }

        return $schema;
    }

    private function _generateSchema($typeDefinitions, $resolveFunctions, $logger, $allowUndefinedInResolve, $resolverValidationOptions) {
        if (empty($typeDefinitions)) {
            throw new SchemaError('Must provide typeDefs');
        }
        if (empty($resolveFunctions)) {
            throw new SchemaError('Must provide resolvers');
        }

        // TODO: check that typeDefinitions is either string or array of strings

        $schema = $this->buildSchemaFromTypeDefinitions($typeDefinitions);

        self::addResolveFunctionsToSchema($schema, $resolveFunctions);

        $this->assertResolveFunctionsPresent($schema, $resolverValidationOptions);

        if (!$allowUndefinedInResolve) {
            $this->addCatchUndefinedToSchema($schema);
        }

        if ($logger) {
            $this->addErrorLoggingToSchema($schema, $logger);
        }

        return $schema;
    }

    private function addErrorLoggingToSchema(&$schema, $logger) {
        //TODO make this work
        return;
        if (!$logger) {
            throw new \ErrorException('Must provide a logger');
        }
        if (!is_callable($logger->log )) {
            throw new \ErrorException('Logger->log must be a function');
        }
        $this->forEachField($schema, function ($field, $typeName, $fieldName) use($logger) {
            $errorHint = "$typeName -> $fieldName";
            $field->resolveFn = $this->decorateWithLogger($field->resolveFn, $logger, $errorHint);
        });
    }

    /*
    * fn: The function to decorate with the logger
    * logger: an object instance of type Logger
    * hint: an optional hint to add to the error's message
    */
    private function decorateWithLogger($fn, $logger, $hint) {
        //TODO make this work
        return;
        if(empty($fn)){
            $fn = function ($source, $args, $context, $info){
                return $this->defaultResolveFn($source, $args, $context, $info);
            };
        }

        $logError = function (\ErrorException $e) {
            if ($hint) {
                $originalMessage = $e->getMessage();
                $message = "Error in resolver \"$hint\" \n \"$e->message\"";
            }
            $newE = new \ErrorException($message);
            $logger->log($newE);
        };
        return function ($root, $args, $ctx, $info) use($fn) {
            try {
                $result = call_user_func($fn, $root, $args, $ctx, $info);
                // If the resolve function returns a Promise log any Promise rejects.
                if ($result && is_callable($result->then) && is_callable($result->catch)) {
                    $result->catch(function ($reason) {
                        // make sure that it's an error we're logging.
                        $error = $reason instanceof \ErrorException ? $reason : new \ErrorException($reason);
                        //logError(error);

                        // We don't want to leave an unhandled exception so pass on error.
                        return $reason;
                    });
                }
                return $result;
            } catch (Exception $e) {
                //logError(e);
                // we want to pass on the error, just in case.
                throw $e;
            }
        };
    }



    private function addCatchUndefinedToSchema($schema) {
        $this->forEachField($schema, function($field, $typeName, $fieldName) {
            $errorHint = "$typeName -> $fieldName";
            $field->resolveFn = $this->decorateToCatchUndefined($field->resolveFn, $errorHint);
        });
    }

    private function decorateToCatchUndefined($fn, $hint) {
        if (empty($fn)){
            $fn = function ($source, $args, $context, $info){
                return $this->defaultResolveFn($source, $args, $context, $info);
            };
        }
        return function ($root, $args, $ctx, $info) use ($fn, $hint) {
            $result = call_user_func($fn, $root, $args, $ctx, $info);
            if (empty($result)) {
                throw new \ErrorException("Resolve function for \"$hint\" returned null");
            }
            return $result;
        };
    }

    /**
    * If a resolve function is not given, then a default resolve behavior is used
    * which takes the property of the source object of the same name as the field
    * and returns it as the result, or if it's a function, returns the result
    * of calling that function.
    */
    private function defaultResolveFn($source, $args, $context, $info) {
        // ensure source is a value for which property access is acceptable.
        if (is_object($source) || is_callable($source)) {
            $property = $source[$fieldName];
            if (is_callable($property)) {
                return $property($args, $context);
            }
            return $property;
        }
    }


    private function assertResolveFunctionsPresent($schema, $resolverValidationOptions) {
        list($requireResolversForArgs, $requireResolversForNonScalar, $requireResolversForAllFields) = $resolverValidationOptions;

        if ($requireResolversForAllFields &&
            ($requireResolversForArgs || $requireResolversForNonScalar)) {
            throw new TypeError('requireResolversForAllFields takes precedence
            over the more specific assertions. Please configure either
            requireResolversForAllFields or requireResolversForArgs /
            requireResolversForNonScalar, but not a combination of them.');
        }

        $this->forEachField($schema, function($field, $typeName, $fieldName) use($requireResolversForAllFields){
            // requires a resolve function for *every* field.
            //if ($requireResolversForAllFields) {
                $this->expectResolveFunction($field, $typeName, $fieldName);
            //}

            // requires a resolve function on every field that has arguments
            if ($requireResolversForArgs && count($field->args)) {
                $this->expectResolveFunction($field, $typeName, $fieldName);
            }

            // requires a resolve function on every field that returns a non-scalar type
            if ($requireResolversForNonScalar && !($this->getNamedTypeNode($field->type) instanceof ScalarType)) {
                $this->expectResolveFunction($field, $typeName, $fieldName);
            }
        });
    }

    function expectResolveFunction($field, $typeName, $fieldName) {
        if (!$field->resolveFn) {
            return;
        }
        if (!is_callable($field->resolveFn)) {
            throw new SchemaError(`Resolver \"$typeName -> $fieldName\" must be a function`);}
    }


    private function buildSchemaFromTypeDefinitions($typeDefinitions) {
        $myDefinitions = $typeDefinitions;
        $astDocument = null;

        if ($this->isDocumentNode($typeDefinitions)) {
            $astDocument = $typeDefinitions;
        }elseif(!is_string($myDefinitions)) {
            if (!is_array($myDefinitions)) {
                $type = gettype($myDefinitions);
                throw new SchemaError('typeDefs must be a string, array or schema AST, got '.$type);
            }
            $myDefinitions = $this->concatenateTypeDefs($myDefinitions);
        }

        if(is_string($myDefinitions)){
            $astDocument = Parser::parse($myDefinitions, ['noLocation' => true]);//TODO remove this later
        }

        $schema = BuildSchema::buildAST($astDocument);
        $extensionsAst = self::extractExtensionDefinitions($astDocument);

        if (count($extensionsAst->definitions)) {
            $schema = ExtendSchema::extend($schema, $extensionsAst);
        }

        return $schema;
    }

    /**
    * @param mixed $ast
    * @returns DocumentNode
    */
    public static function extractExtensionDefinitions(DocumentNode $ast) {
        $extensionDefs = Utils::filter($ast->definitions, function ($node){
            return $node->kind == NodeKind::TYPE_EXTENSION_DEFINITION;
        });
        $ast->definitions = $extensionDefs;
        return $ast;
    }

    public static function addResolveFunctionsToSchema(&$schema, $resolveFunctions) {
        foreach ($resolveFunctions as $typeName => $resolver) {
            $type = $schema->getType($typeName);
            if (!$type && $typeName !== '__schema') {
                throw new SchemaError("\"$typeName\" defined in resolvers, but not in schema");
            }

            foreach ($resolver as $fieldName => $callable) {
                if (Utils::startsWith($fieldName, '__')) {
                    // this is for isTypeOf and resolveType and all the other stuff.
                    // TODO require resolveType for unions and interfaces.
                    $type[substr($fieldName,2)] = $callable;
                    break;
                }

                if ($type instanceof ScalarType) {
                    $type[$fieldName] = $callable;
                    break;
                }

                $fields = self::getFieldsForType($type);
                if (!$fields) {
                    throw new SchemaError("\"$typeName\" was defined in resolvers, but it's not an object, have no fields");
                }

                if (!$fields[$fieldName]) {
                    throw new SchemaError("\"$typeName -> $fieldName\" defined in resolvers, but not in schema");
                }
                $field = $fields[$fieldName];
                $fieldResolve = $callable;
                if (is_callable($fieldResolve)) {
                    // for convenience. Allows shorter syntax in resolver definition file
                    self::setFieldProperties($field, ['resolve' => $fieldResolve ]);
                } else {
                    if (!is_array($fieldResolve)) {
                        throw new SchemaError("Resolver \"$typeName -> $fieldName\" must be object or function");
                    }
                    self::setFieldProperties($field, $fieldResolve);
                }
            }
        };
    }

    /**
    * @param \GraphQL\Type\Schema $schema
    * @param mixed $resolveFunctions
    */
    public static function addResolveFunctionsToSchemaOld(&$schema, $resolveFunctions) {
        foreach ($resolveFunctions as $typeName => $fnList) {
            /**
            * @var \GraphQL\Type\Definition\Type
            */
            $type = $schema->getType($typeName);

            if (!$type && $typeName !== '__schema') {
                throw new SchemaError("\"$typeName\" defined in resolvers, but not in schema");
            }
            foreach ($resolveFunctions[$typeName] as $fieldName => $resolver) {
                if (Utils::startsWith($fieldName,'__')) {
                    // this is for isTypeOf and resolveType and all the other stuff.
                    // TODO require resolveType for unions and interfaces.
                    $type[substr($fieldName, 2)] = $resolver;
                    break;
                }

                if ($type instanceof ScalarType) {
                    $type[$fieldName] = $resolver;
                    break;
                }

                $fields = self::getFieldsForType($type);
                if (empty($fields)) {
                    throw new SchemaError("$typeName was defined in resolvers, but it's not an object, have no fields");
                }

                if (!$fields[$fieldName]) {
                    throw new SchemaError("$typeName -> $fieldName defined in resolvers, but not in schema");
                }

                $field = $fields[$fieldName];
                $fieldResolve = $resolveFunctions[$typeName][$fieldName];

                if (is_callable($fieldResolve)) {
                    $field->resolveFn = $fieldResolve;
                } else {
                    if (!is_object($fieldResolve)) {
                        throw new SchemaError("Resolver \"$typeName -> $fieldName\" must be object or function");
                    }
                    $field->resolveFn = $fieldResolve;
                }
            };
        };
        return $schema;
    }

    private static function setFieldProperties(\GraphQL\Type\Definition\FieldDefinition $field, $propertiesObj) {
        foreach ($propertiesObj as $propertyName => $prop) {
            $fieldName = $propertyName;
            if($propertyName == 'resolve') $fieldName.='Fn';
            $field->{$fieldName} = $propertiesObj[$propertyName];
        }
        return $field;
    }


    private static function getFieldsForType($type) {
        if (($type instanceof ObjectType) || ($type instanceof InterfaceType)) {
            return $type->getFields();
        } else {
            return null;
        }
    }


    // wraps all resolve functions of query, mutation or subscription fields
    // with the provided function to simulate a root schema level resolve function
    function addSchemaLevelResolveFunction($schema, $fn) {
        // TODO test that schema is a schema, fn is a function
        $rootTypes = ([
            $schema->getQueryType(),
            $schema->getMutationType(),
            $schema->getSubscriptionType(),
        ]);
        foreach ($rootTypes as $type) {
            $rootResolveFn = runAtMostOncePerRequest($fn);
            $fields = $type->getFields();
            foreach ($fields as $fieldName) {
                if ($type == $schema->getSubscriptionType()) {
                    $fields[$fieldName]->resolveFn = $this->wrapResolver($fields[$fieldName]->resolveFn, $fn);
                } else {
                    $fields[$fieldName]->resolveFn = $this->wrapResolver($fields[$fieldName]->resolveFn, $rootResolveFn);
                }
            };
        };
    }

    private function concatenateTypeDefs($typeDefinitionsArray, $calledFunctionRefs){
        $resolvedTypeDefinitions = [];
        foreach ($typeDefinitionsArray as $typeDef) {
            if ($this->isDocumentNode($typeDef)) {
                $typeDef = Printer::doPrint($typeDef);
            }

            if (is_callable($typeDef)) {
                if (!in_array($typeDef, $calledFunctionRefs)) {
                    $calledFunctionRefs[] = $typeDef;
                    $typeDefArr = call_user_func ($typeDef);
                    $deeperTypeDef = $this->concatenateTypeDefs($typeDefArr, $calledFunctionRefs);
                    $resolvedTypeDefinitions = array_merge($resolvedTypeDefinitions, $deeperTypeDef);
                }
            } elseif(is_string($typeDef)) {
                $resolvedTypeDefinitions[] = trim($typeDef);
            } else {
                $type = gettype($typeDef);
                throw new SchemaError('typeDef array must contain only strings and functions, got '.$type);
            }
        }

        Utils::mapValues($resolvedTypeDefinitions, function($x){
           return trim($x);
        });

        return implode("\n",array_unique($resolvedTypeDefinitions));
    }

    private function isDocumentNode($typeDefinitions){
        if(is_string($typeDefinitions)) return false;
        return $typeDefinitions->kind == NodeKind::DOCUMENT;
    }

    function forEachField($schema, $fn) {
        $typeMap = $schema->getTypeMap();
        foreach ($typeMap as $typeName => $type) {
            if (!Utils::startsWith($this->getNamedTypeNode($type)->name, '__') && $type instanceof ObjectType) {
                $fields = $type->getFields();
                foreach ($fields as $fieldName => $field) {

                    call_user_func($fn, $field, $typeName, $fieldName);
                };
            }
        };
    }

    private function getNamedTypeNode($typeNode)
    {
        $namedType = $typeNode;
        while ($namedType->kind === NodeKind::LIST_TYPE || $namedType->kind === NodeKind::NON_NULL_TYPE) {
            $namedType = $namedType->type;
        }
        return $namedType;
    }
}