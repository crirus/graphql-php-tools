<?php
namespace Olamobile\GraphQL\Tools;    

use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\GraphQL;

use GraphQL\Language\Parser;
use GraphQL\Language\AST\NodeKind;

class TypeRegistry {
    public $fragmentReplacements;
    private $types;
    private $schemaByField; //query | mutation

    public function __construct() {
        $this->types = [];
        $this->schemaByField = [
            'query' => [],
            'mutation' => [],
        ];
        $this->fragmentReplacements = [];
    }

    public function getSchemaByField($operation, $fieldName) {
        return $this->schemaByField[$operation][$fieldName];
    }

    public function getAllTypes() {
        return array_values($this->types);
    }

    public function getType($name) {
        if (!$this->types[$name]) {
            throw new \ErrorException("No such type: \"$name\"");
        }
        return $this->types[$name];
    }

    public function resolveType($type) {
        if ($type instanceof ListOfType) {
            return Type::listOf($this->resolveType($type->getWrappedType(true)));
        } else if ($type instanceof NonNull) {
            return Type::nonNull($this->resolveType($type->getWrappedType(true)));
        } else if (Type::isNamedType($type)) {
            return $this->getType(Type::getNamedType($type)->name);
        } else {
            return $type;
        }
    }

    public function addSchema($schema) {
        $query = $schema->getQueryType();
        if ($query) {
            $fieldNames = array_keys($query->getFields());
            foreach ($fieldNames as $fieldName) {
                $this->schemaByField['query'][$fieldName] = $schema;    
            }
        }
        $mutation = $schema->getMutationType();
        if ($mutation) {
            $fieldNames = array_keys($mutation->getFields());
            foreach ($fieldNames as $fieldName) {
                $this->schemaByField['mutation'][$fieldName] = $schema;            
            }
        }
    }

    public function addType($name, $type, $onTypeConflict=null) {
        if ($this->types[$name]) {
            if (!empty($onTypeConflict)) {
                $type = call_user_func($onTypeConflict, $this->types[$name], $type);
            } else {
                throw new Error("Type name conflict: \"$name\"");
            }
        }
        $this->types[$name] = $type;
    }

    public function addFragment($typeName, $fieldName, $fragment) {
        if (!$this->fragmentReplacements[$typeName]) {
            $this->fragmentReplacements[$typeName] = [];
        }
        $this->fragmentReplacements[$typeName][$fieldName] = $this->parseFragmentToInlineFragment($fragment);
    }

    private function parseFragmentToInlineFragment($definitions) {
        $document = Parser::parse($definitions);
        foreach ($document->definitions as $key => $definition) {
            if ($definition->kind == NodeKind::FRAGMENT_DEFINITION) {
                return [
                    'kind' => NodeKind::INLINE_FRAGMENT,
                    'typeCondition' =>  $definition->typeCondition,
                    'selectionSet' => $definition->selectionSet,
                ];
            }
        }
        throw new \ErrorException("Could not parse fragment");
    }
}