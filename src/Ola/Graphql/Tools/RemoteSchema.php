<?php
namespace Olamobile\GraphQL\Tools;

use Olamobile\GraphQL\Types;

use GraphQL\Schema;
use GraphQL\Type\Introspection;
use GraphQL\Utils\BuildClientSchema;
use GraphQL\Utils\RoutingResolvers;
use GraphQL\Utils\ExecutableSchema;

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
        curl_setopt($chObj, CURLOPT_HEADER, true);
        curl_setopt($chObj, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($chObj, CURLINFO_HEADER_OUT, true);
        curl_setopt($chObj, CURLOPT_POSTFIELDS, $json);

        $response = curl_exec($chObj);
        
        //DebugBreak('1@localhost');
        $introspectionResponse = array_pop(explode("\r\n\r\n",$response));
        $introspectionResult = json_decode($introspectionResponse);

        $schema = BuildClientSchema::build($introspectionResult->data);
        $schema = RoutingResolvers::makeRemoteExecutableSchema($schema, $endpointURL);
        
        return $schema;
    }
}
