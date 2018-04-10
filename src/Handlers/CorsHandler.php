<?php
/**
 * restServer, a PSR HTTP Message rest server implementation
 *
 * This file is a part of restServer.
 *
 * Copyright 2018 Kjell-Inge Gustafsson, kigkonsult, All rights reserved
 * Link      http://kigkonsult.se/restServer/index.php
 * Version   0.9.23
 * License   Subject matter of licence is the software restServer.
 *           The above copyright, link, package and version notices and
 *           this licence notice shall be included in all copies or
 *           substantial portions of the restServer.
 *           restServer can be used either under the terms of
 *           a proprietary license, available at <https://kigkonsult.se/>
 *           or the GNU Affero General Public License, version 3:
 *           restServer is free software: you can redistribute it and/or
 *           modify it under the terms of the GNU Affero General Public License
 *           as published by the Free Software Foundation, either version 3 of
 *           the License, or (at your option) any later version.
 *           restServer is distributed in the hope that it will be useful,
 *           but WITHOUT ANY WARRANTY; without even the implied warranty of
 *           MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *           GNU Affero General Public License for more details.
 *           You should have received a copy of the GNU Affero General Public
 *           License along with this program.
 *           If not, see <http://www.gnu.org/licenses/>.
 */

namespace Kigkonsult\RestServer\Handlers;

use Psr\Http\Message\ServerRequestInterface;
use Kigkonsult\RestServer\ResponseInterface;
use Kigkonsult\RestServer\RestServer;
use RuntimeException;

/**
 * corsHandler provides simple request support, general for ALL requests uri target
 *
 * @see cfg/cfg.2.cors.php
 * @see https://www.html5rocks.com/en/tutorials/cors/
 * @see https://www.html5rocks.com/static/images/cors_server_flowchart.png
 */
class CorsHandler extends AbstractCAHandler
{
    /**
     * Request header
     */
    const ORIGIN                        = 'Origin';

    /**
     * config key
     */
    const CORS                          = 'cors';

    /**
     * required Origin missing,  400 - Bad Request
     */
    const ERRORCODE1                    = 'errorCode1';

    /**
     * incorrect Origin, 403 - Forbidden
     */
    const ERRORCODE2                    = 'errorCode2';

    /**
     * no accepted request method in 'Access-Control-Request-Method',   406 - Not Acceptable
     */
    const ERRORCODE3                    = 'errorCode3';

    /**
     * one or more non-accepted request header(s) in 'Access-Control-Request-Headers', 406 - Not Acceptable
     */
    const ERRORCODE4                    = 'errorCode4';

    /**
     * cors header prefix
     */
    const CORSHEADERPRSFIX              = 'Access-Control-';

    /**
     * Request header
     */
    const ACCESSCONTROLREQUESTMETHOD    = 'Access-Control-Request-Method';

    /**
     * Request header
     */
    const ACCESSCONTROLREQUESTHEADERS   = 'Access-Control-Request-Headers';

    /**
     * Response Header
     */
    const ACCESSCONTROLALLOWMETHODS     = 'Access-Control-Allow-Methods';

    /**
     * Response Header
     */
    const ACCESSCONTROLALLOWHEADERS     = 'Access-Control-Allow-Headers';

    /**
     * Response Header
     */
    const ACCESSCONTROLALLOWORIGIN      = 'Access-Control-Allow-Origin';

    /**
     * Response Header
     */
    const ACCESSCONTROLALLOWCREDENTIALS = 'Access-Control-Allow-Credentials';

    /**
     * Response Header
     */
    const ACCESSCONTROLEXPOSEHEADERS    = 'Access-Control-Expose-Headers';

    /**
     * Response Header
     */
    const ACCESSCONTROLMAXAGE           = 'Access-Control-Max-Age';

    /**
     * Default error codes
     */
    private static $defaults = [
        self::ERRORCODE1 => 400,
        self::ERRORCODE2 => 403,
        self::ERRORCODE3 => 406,
        self::ERRORCODE4 => 406,
    ];

    /**
     * misc.
     */
    private static $COMMASP = ', ';

    /**
     * Handler validating cors
     *
     * @see https://www.html5rocks.com/static/images/cors_server_flowchart.png
     *
     * Requires config, see cfg/cfg.2.cors.php
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @return array [ ServerRequestInterface, ResponseInterface ]
     */
    public static function validateCors(
        ServerRequestInterface $request,
        ResponseInterface      $response
    ) {
        static $ERRORFMT1  = 'Origin header expected';
        static $ERRORFMT2a = 'Origin header found (%s) but not expected';
        static $ERRORFMT2b = 'Origin found (%s) but not valid';
        static $ERRORFMT34 = 'Error in cors preflight request';
        static $TRUE       = 'true';

        $corsCfg           = parent::getConfig( $request, self::CORS, self::$defaults );
        $corsIgnore        = ( isset( $corsCfg[RestServer::IGNORE] ) && ( true === $corsCfg[RestServer::IGNORE] ));
        $corsExpected      = ( isset( $corsCfg[RestServer::ALLOW] ));
        $hasOriginHeader   = $request->hasHeader( self::ORIGIN );

        // Origin not expected and not found, ok
        if( ! $corsExpected && ! $hasOriginHeader ) {
            return [
                $request,
                $response,
            ];
        } // end if
        // Origin not expected but found and ignored, ok
        if( $corsIgnore && $hasOriginHeader ) {
            return [
                $request,
                $response,
            ];
        } // end if
        // Origin expected but not found, error 1
        if( $corsExpected && ! $hasOriginHeader ) {
            return self::doLogReturn(
                $request->withAttribute( RestServer::ERROR, true ),
                $response->withStatus( $corsCfg[self::ERRORCODE1] ),
                new RuntimeException( $ERRORFMT1 ),
                RestServer::WARNING
            );
        } // end if

        $requestOriginHeaderValue = $request->getHeader( self::ORIGIN )[0];
        // Origin not expected but found and not ignored, error 2a
        if( ! $corsExpected ) {
            return self::doLogReturn(
                $request->withAttribute( RestServer::ERROR, true ),
                $response->withStatus( $corsCfg[self::ERRORCODE2] ),
                new RuntimeException( sprintf( $ERRORFMT2a, $requestOriginHeaderValue )),
                RestServer::WARNING
            );
        } // end if
        // Origin found but not valid, error 2b
        if( ! self::checkOrigin( $corsCfg, $requestOriginHeaderValue )) {
            return self::doLogReturn(
                $request->withAttribute( RestServer::ERROR, true ),
                $response->withStatus( $corsCfg[self::ERRORCODE2] ),
                new RuntimeException( sprintf( $ERRORFMT2b, $requestOriginHeaderValue )),
                RestServer::WARNING
            );
        } // end if

        // preflight request
        if( self::isPreflightRequest( $request )) {
            $errorCode = null;
            $response  = self::doPreflightRequest(
                $request,
                $response,
                $corsCfg,
                $errorCode
            );
            if ( ! empty( $errorCode )) {
                return self::doLogReturn(
                    $request->withAttribute( RestServer::ERROR, true ),
                    $response->withStatus( $errorCode ),
                    new RuntimeException( $ERRORFMT34 ),
                    RestServer::WARNING
                );
            } // end if
        } // end if
        // NO preflight request and...
        elseif ( isset( $corsCfg[self::ACCESSCONTROLEXPOSEHEADERS] )) {
            $response = $response->withHeader(
                self::ACCESSCONTROLEXPOSEHEADERS,
                \implode( self::$COMMASP, (array) $corsCfg[self::ACCESSCONTROLEXPOSEHEADERS] )
            );
        } // end elseif

        $response = $response->withHeader(
            self::ACCESSCONTROLALLOWORIGIN,
            $requestOriginHeaderValue
        );

        if ( isset( $corsCfg[self::ACCESSCONTROLALLOWCREDENTIALS] ) &&
            (bool) $corsCfg[self::ACCESSCONTROLALLOWCREDENTIALS] ) {
            // allow cookies
            $response = $response->withHeader(
                self::ACCESSCONTROLALLOWCREDENTIALS,
                $TRUE
            );
        }
        return [
            $request,
            $response,
        ];
    }

    /**
     * Return accepted request Origin header OR not found status
     *
     * @param array  $corsCfg
     * @param String $requestOriginHeaderValue
     * @return bool
     * @access private
     * @static
     */
    private static function checkOrigin(
        array $corsCfg,
              $requestOriginHeaderValue
    ) {
        $found = false;
        foreach ( $corsCfg[RestServer::ALLOW] as $acceptedOrigin ) {
            if (( self::AST == $acceptedOrigin ) ||  // all accepted OR accepted found
               ( 0 == \strcasecmp( $acceptedOrigin, $requestOriginHeaderValue ))) {
                $found = true;
                break;
            }
        } // end foreach
        return $found;
    }

    /**
     * Return bool true if it is a preflight request
     *
     * @param ServerRequestInterface $request
     * @return bool
     * @access private
     * @static
     */
    private static function isPreflightRequest(
        ServerRequestInterface $request
    ) {
        return (( 0 == \strcasecmp( RequestMethodHandler::METHOD_OPTIONS, $request->getMethod())) &&
            $request->hasHeader( self::ACCESSCONTROLREQUESTMETHOD ));
    }

    /**
     * Return response with cors headers, manage a preflight request
     *
     * @see https://www.html5rocks.com/static/images/cors_server_flowchart.png
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param array                  $corsCfg
     * @param int                    $errorCode
     * @return ResponseInterface
     * @access private
     * @static
     */
    private static function doPreflightRequest(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $corsCfg,
                             & $errorCode = 0
    ) {
        $allowedMethods = self::getAllowedMethods( $request );
        $found          = false;
        $requestMethod  = $request->getHeader( self::ACCESSCONTROLREQUESTMETHOD )[0];
        foreach ( $allowedMethods as $allowMethod ) {
            if ( 0 == \strcasecmp( $allowMethod, $requestMethod )) {
                $found = true;
                break;
            }
        } // end foreach
        if ( ! $found ) { // invalid access control request method, error3
            $errorCode = $corsCfg[self::ERRORCODE3];
            return $response;
        }
        $response = $response->withHeader(
            self::ACCESSCONTROLALLOWMETHODS,
            \implode( self::$COMMASP, $allowedMethods )
        );

        $allowHeaders = ( isset(  $corsCfg[self::ACCESSCONTROLALLOWHEADERS] ) &&
                        ! empty(  $corsCfg[self::ACCESSCONTROLALLOWHEADERS] ))
                        ? (array) $corsCfg[self::ACCESSCONTROLALLOWHEADERS]
                        : [];
        if ( $request->hasHeader( self::ACCESSCONTROLREQUESTHEADERS )) {
            $headerValue = $request->getHeader( self::ACCESSCONTROLREQUESTHEADERS )[0];
            $requestHeaderValues = \explode( self::COMMA, $headerValue );
            foreach ( $requestHeaderValues as $requestHeaderValue ) {
                $found = false;
                foreach ( $allowHeaders as $allowHeader ) {
                    if ( 0 == \strcasecmp( \trim( $requestHeaderValue ), $allowHeader )) {
                        $found = true;
                        break;
                    }
                } // end foreach
                if ( ! $found ) { // invalid access control request header, error4
                    $errorCode = $corsCfg[self::ERRORCODE4];
                    return $response;
                } // end if
            } // end foreach
        } // end if

        if ( ! empty( $allowHeaders )) {
            $response = $response->withHeader(
                self::ACCESSCONTROLALLOWHEADERS,
                \implode( self::$COMMASP, $allowHeaders )
            );
        }
        if ( \array_key_exists( self::ACCESSCONTROLMAXAGE, $corsCfg )) {
            $response = $response->withHeader(
                self::ACCESSCONTROLMAXAGE,
                (int) $corsCfg[self::ACCESSCONTROLMAXAGE]
            );
        }
        return $response;
    }
    /**
     * Return (array) allowed methods
     *
     * @param ServerRequestInterface $request
     * @return array
     * @access private
     * @static
     */
    private static function getAllowedMethods(
        ServerRequestInterface $request
    ) {
        $allowedMethods = $request->getAttribute( RestServer::REQUESTMETHODURI, [] );
        if ( ! empty( $allowedMethods )) {
            $allowedMethods = \array_keys( $allowedMethods );
        }
        return RequestMethodHandler::extendAllowedMethods( $request, $allowedMethods );
    }
}
