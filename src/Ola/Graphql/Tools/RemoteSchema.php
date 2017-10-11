<?php
namespace Ola\GraphQL\Tools;

use Ola\GraphQL\Tools\BuildClientSchema;
use Ola\GraphQL\Tools\RoutingResolvers;
use Ola\GraphQL\Tools\ExecutableSchema;

use Ola\GraphQL\Types;

use GraphQL\Schema;
use GraphQL\Type\Introspection;


class RemoteSchema{

    /**
    * put your comment there...
    *
    * @param mixed $endpoints
    * @param mixed $linkSchema
    * @param mixed $resolvers
    * @param mixed $fetcher
    *
    * @return \GraphQL\Type\Schema
    */
    public static function buildRemoteSchema($endpoints, $linkSchema, $resolvers, $fetcher = null){
        if(!is_array($endpoints)) throw new \Exception('you must provide graphql endpoints');
        foreach ($endpoints as $value) {
            $schemas[] = self::introspectSchema($value, $fetcher);
        }

        $schemas[] = $linkSchema;

        //$ast = SchemaPrinter::doPrint($schemas[0]);

        $schema = MergeSchemas::mergeSchemas($schemas, $resolvers, null);

        return $schema;
    }

    //TODO make this calls using promises: $endpointURL to Fetcher
    public static function introspectSchema($endpointURL, $fetcher = null){
        $iquery = Introspection::getIntrospectionQuery();

        $json = json_encode(['query' => $iquery]);//, 'variables' => $variables

        $chObj = curl_init();
        curl_setopt($chObj, CURLOPT_URL, $endpointURL);
        curl_setopt($chObj, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chObj, CURLOPT_POST, 1);
        curl_setopt($chObj, CURLOPT_HEADER, false);
        curl_setopt($chObj, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($chObj, CURLINFO_HEADER_OUT, true);
        curl_setopt($chObj, CURLOPT_POSTFIELDS, $json);

        $response = curl_exec($chObj);

        $introspectionResponse = array_pop(explode("\r\n\r\n",$response));
        $introspectionResult = json_decode($introspectionResponse);

        if(!$introspectionResult->data) throw new \Exception("Error reading schema introspection for endpoint ".$endpointURL);
        
        $schema = BuildClientSchema::build($introspectionResult->data);
        $schema = RoutingResolvers::makeRemoteExecutableSchema($schema, $endpointURL);

        
        
        return $schema;
    }
}
