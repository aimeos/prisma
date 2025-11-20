<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;


class AWS extends Base
{
    private string $accessKey;
    private string $secretKey;
    private string $sessionToken;
    private string $region;
    private string $host;


    public function __construct( array $config )
    {
        if( !isset( $config['access_key'] ) ) {
            throw new PrismaException( sprintf( 'No access key' ) );
        }

        if( !isset( $config['secret_key'] ) ) {
            throw new PrismaException( sprintf( 'No secret key' ) );
        }

        $this->accessKey = $config['access_key'];
        $this->secretKey = $config['secret_key'];
        $this->sessionToken = $config['session_token'] ?? '';
        $this->region = $config['region'] ?? 'us-east-1';
        $this->host = "bedrock-runtime.{$this->region}.amazonaws.com";

        $this->baseUrl( "https://{$this->host}" );
    }


    /**
     * Signs the request using AWS Signature Version 4
     *
     * @param string $service Service name, e.g 'bedrock'
     * @param string $uri Request URI
     * @param string $payload Request payload
     * @return array<string, string> Signed headers
     */
    public function sign4( string $service, string $uri, string $payload ) : array
    {
        $dateStamp = gmdate( 'Ymd' );
        $amzDate = gmdate( 'Ymd\THis\Z' );
        $hashedPayload = hash( 'sha256', $payload );

        $canonicalHeaders =
            "content-type:application/json\n" .
            "host:{$this->host}\n" .
            "x-amz-date:$amzDate\n";

        if( $this->sessionToken ) {
            $canonicalHeaders .= "x-amz-security-token:{$this->sessionToken}\n";
        }

        $signedHeaders = "content-type;host;x-amz-date" . ( $this->sessionToken ? ";x-amz-security-token" : "" );
        $canonicalRequest = implode("\n", [
            'POST',
            $uri,
            "",
            $canonicalHeaders,
            $signedHeaders,
            $hashedPayload
        ]);

        $canonicalRequestHash = hash( 'sha256', $canonicalRequest );
        $credentialScope = "$dateStamp/{$this->region}/{$service}/aws4_request";

        $stringToSign = implode("\n", [
            "AWS4-HMAC-SHA256",
            $amzDate,
            $credentialScope,
            $canonicalRequestHash
        ]);

        $kSecret  = "AWS4" . $this->secretKey;
        $kDate    = hash_hmac( 'sha256', $dateStamp, $kSecret, true );
        $kRegion  = hash_hmac( 'sha256', $this->region, $kDate, true );
        $kService = hash_hmac( 'sha256', $service, $kRegion, true );
        $kSigning = hash_hmac( 'sha256', "aws4_request", $kService, true );
        $signature = hash_hmac( 'sha256', $stringToSign, $kSigning );

        $authorization = "AWS4-HMAC-SHA256 " .
            "Credential={$this->accessKey}/$credentialScope, " .
            "SignedHeaders=$signedHeaders, " .
            "Signature=$signature";

        $headers = [
            "Content-Type" => "application/json",
            "Authorization" => $authorization,
            "X-Amz-Date" => $amzDate,
        ];

        if( $this->sessionToken ) {
            $headers["X-Amz-Security-Token"] = $this->sessionToken;
        }

        return $headers;
    }
}