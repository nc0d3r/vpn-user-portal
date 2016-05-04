<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace fkooman\VPN\UserPortal;

use fkooman\Http\Exception\BadRequestException;
use fkooman\Http\RedirectResponse;
use fkooman\Http\Request;
use fkooman\Http\Response;
use fkooman\Rest\Plugin\Authentication\UserInfoInterface;
use fkooman\Rest\Service;
use fkooman\Rest\ServiceModuleInterface;
use fkooman\Tpl\TemplateManagerInterface;
use BaconQrCode\Renderer\Image\Png;
use BaconQrCode\Writer;
use Otp\GoogleAuthenticator;
use Otp\Otp;
use Base32\Base32;
use fkooman\Http\Session;

class VpnPortalModule implements ServiceModuleInterface
{
    /** @var \fkooman\Tpl\TemplateManagerInterface */
    private $templateManager;

    /** @var VpnConfigApiClient */
    private $vpnConfigApiClient;

    /** @var VpnServerApiClient */
    private $vpnServerApiClient;

    /** @var UserTokens */
    private $userTokens;

    /** @var array */
    private $remoteConfig;

    /** @var \fkooman\Http\Session */
    private $session;

    public function __construct(TemplateManagerInterface $templateManager, VpnConfigApiClient $vpnConfigApiClient, VpnServerApiClient $vpnServerApiClient, UserTokens $userTokens, Session $session, array $remoteConfig)
    {
        $this->templateManager = $templateManager;
        $this->vpnConfigApiClient = $vpnConfigApiClient;
        $this->vpnServerApiClient = $vpnServerApiClient;
        $this->userTokens = $userTokens;
        $this->session = $session;
        $this->remoteConfig = $remoteConfig;
    }

    public function init(Service $service)
    {
        $noAuth = array(
            'fkooman\Rest\Plugin\Authentication\AuthenticationPlugin' => array(
                'enabled' => false,
            ),
        );

        $userAuth = array(
            'fkooman\Rest\Plugin\Authentication\AuthenticationPlugin' => array(
                'activate' => array('user'),
            ),
        );

        /* REDIRECTS **/
        $service->get(
            '/config/',
            function (Request $request) {
                return new RedirectResponse($request->getUrl()->getRootUrl(), 301);
            },
            $noAuth
        );

        $service->get(
            '/',
            function (Request $request) {
                return new RedirectResponse($request->getUrl()->getRootUrl().'home', 302);
            },
            $noAuth
        );

        /* PAGES */
        $service->get(
            '/home',
            function (Request $request, UserInfoInterface $u) {
                return $this->templateManager->render(
                    'vpnPortalHome',
                    array(
                    )
                );
            },
            $userAuth
        );

        $service->get(
            '/new',
            function (Request $request, UserInfoInterface $u) {
                $serverInfo = $this->vpnServerApiClient->getInfo();
                $userInfo = $this->vpnServerApiClient->getUserInfo($u->getUserId());

                return $this->templateManager->render(
                    'vpnPortalNew',
                    array(
                        'tfaRequired' => $serverInfo['tfa'] && !$userInfo['otp_secret'],
                        'advanced' => (bool) $request->getUrl()->getQueryParameter('advanced'),
                        'cnLength' => 63 - strlen($u->getUserId()),
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/new',
            function (Request $request, UserInfoInterface $u) {
                $configName = $request->getPostParameter('name');
                $type = $request->getPostParameter('type');

                return $this->getConfig($request, $u->getUserId(), $configName, $type);
            },
            $userAuth
        );

        $service->get(
            '/configurations',
            function (Request $request, UserInfoInterface $u) {
                $certList = $this->vpnConfigApiClient->getCertList($u->getUserId());
                $configList = $this->vpnServerApiClient->getConfig($u->getUserId());
                $serverInfo = $this->vpnServerApiClient->getInfo();

                $activeVpnConfigurations = array();
                $revokedVpnConfigurations = array();
                $disabledVpnConfigurations = array();
                $expiredVpnConfigurations = array();

                foreach ($certList['items'] as $c) {
                    if ('E' === $c['state']) {
                        $expiredVpnConfigurations[] = $c;
                    } elseif ('R' === $c['state']) {
                        $revokedVpnConfigurations[] = $c;
                    } elseif ('V' === $c['state']) {
                        $commonName = $u->getUserId().'_'.$c['name'];
                        $c['disable'] = false;
                        if (array_key_exists($commonName, $configList['items'])) {
                            if ($configList['items'][$commonName]['disable']) {
                                $c['disable'] = true;
                            }
                        }

                        if ($c['disable']) {
                            $disabledVpnConfigurations[] = $c;
                        } else {
                            $activeVpnConfigurations[] = $c;
                        }
                    }
                }

                return $this->templateManager->render(
                    'vpnPortalConfigurations',
                    array(
                        'activeVpnConfigurations' => $activeVpnConfigurations,
                        'disabledVpnConfigurations' => $disabledVpnConfigurations,
                        'revokedVpnConfigurations' => $revokedVpnConfigurations,
                        'expiredVpnConfigurations' => $expiredVpnConfigurations,
                        'serverInfo' => $serverInfo,
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/revoke',
            function (Request $request, UserInfoInterface $u) {
                $configName = $request->getPostParameter('name');
                $formConfirm = $request->getPostParameter('confirm');

                if (is_null($formConfirm)) {
                    // ask for confirmation
                    return $this->templateManager->render(
                        'vpnPortalConfirmRevoke',
                        array(
                            'configName' => $configName,
                        )
                    );
                }

                if ('yes' === $formConfirm) {
                    // user said yes
                    $this->revokeConfig($u->getUserId(), $configName);
                }

                return new RedirectResponse($request->getUrl()->getRootUrl().'configurations', 302);
            },
            $userAuth
        );

        $service->get(
            '/account',
            function (Request $request, UserInfoInterface $u) {
                $userInfo = $this->vpnServerApiClient->getUserInfo($u->getUserId());

                return $this->templateManager->render(
                    'vpnPortalAccount',
                    array(
                        'otpEnabled' => $userInfo['otp_secret'],
                        'userId' => $u->getUserId(),
                        'userTokens' => $this->userTokens->getUserAccessTokens($u->getUserId()),
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/setLanguage',
            function (Request $request, UserInfoInterface $u) {
                $requestLang = $request->getPostParameter('language');
                Utils::validateLanguage($requestLang);
                $this->session->set('activeLanguage', $requestLang);
                // redirect
                return new RedirectResponse($request->getPostParameter('redirect_to'));
            },
            $userAuth
        );

        $service->post(
            '/deleteTokens',
            function (Request $request, UserInfoInterface $u) {
                $this->userTokens->deleteUserAccessTokens($u->getUserId(), $request->getPostParameter('client_id'));

                return new RedirectResponse($request->getUrl()->getRootUrl().'account', 302);
            },
            $userAuth
        );

        $service->get(
            '/documentation',
            function (Request $request, UserInfoInterface $u) {
                return $this->templateManager->render(
                    'vpnPortalDocumentation',
                    array(
                    )
                );
            },
            $userAuth
        );

        $service->get(
            '/otp',
            function (Request $request, UserInfoInterface $u) {
                $otpSecret = GoogleAuthenticator::generateRandom();
                $serverInfo = $this->vpnServerApiClient->getInfo();

                return $this->templateManager->render(
                    'vpnPortalOtp',
                    array(
                        'tfa' => $serverInfo['tfa'],
                        'secret' => $otpSecret,
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/otp',
            function (Request $request, UserInfoInterface $u) {
                $otpSecret = $request->getPostParameter('otp_secret');
                self::validateOtpSecret($otpSecret);
                $otpKey = $request->getPostParameter('otp_key');
                self::validateOtpKey($otpKey);

                $otp = new Otp();
                if ($otp->checkTotp(Base32::decode($otpSecret), $otpKey)) {
                    // we do not keep log of the used keys here
                    // XXX is that acceptable?

                    // store the secret in the vpn-server-api instance
                    $this->vpnServerApiClient->setOtpSecret($u->getUserId(), $otpSecret);

                    return new RedirectResponse($request->getUrl()->getRootUrl().'account', 302);
                }

                return $this->templateManager->render(
                    'vpnPortalErrorOtpEnroll',
                    []
                );
            },
            $userAuth
        );

        $service->get(
            '/otp-qr-code',
            function (Request $request, UserInfoInterface $u) {
                $otpSecret = $request->getUrl()->getQueryParameter('otp_secret');
                self::validateOtpSecret($otpSecret);

                $httpHost = $request->getUrl()->getHost();
                if (false !== strpos($httpHost, ':')) {
                    // strip port
                    $httpHost = substr($httpHost, 0, strpos($httpHost, ':'));
                }

                $otpAuthUrl = sprintf(
                    'otpauth://totp/%s:%s?secret=%s&issuer=%s',
                    $httpHost,
                    $u->getUserId(),
                    $otpSecret,
                    $httpHost
                );

                $renderer = new Png();
                $renderer->setHeight(256);
                $renderer->setWidth(256);
                $writer = new Writer($renderer);
                $qrCode = $writer->writeString($otpAuthUrl);

                $response = new Response(200, 'image/png');
                $response->setBody($qrCode);

                return $response;
            },
            $userAuth
        );
    }

    private function getConfig(Request $request, $userId, $configName, $type)
    {
        Utils::validateConfigName($configName);

        // userId + configName length cannot be longer than 64 as the
        // certificate CN cannot be longer than 64
        if (64 < strlen($userId) + strlen($configName) + 1) {
            throw new BadRequestException(
                sprintf('commonName length MUST not exceed %d', 63 - strlen($userId))
            );
        }

        // make sure the configuration does not exist yet
        // XXX: this should be optimized a bit...
        $certList = $this->vpnConfigApiClient->getCertList($userId);
        foreach ($certList['items'] as $cert) {
            if ($configName === $cert['name']) {
                return $this->templateManager->render(
                    'vpnPortalErrorConfigExists',
                    array(
                        'configName' => $configName,
                    )
                );
            }
        }

        $certData = $this->vpnConfigApiClient->addConfiguration($userId, $configName);

        $remoteEntities = ['remote' => $this->remoteConfig];

        $serverInfo = $this->vpnServerApiClient->getInfo();

        $clientConfig = new ClientConfig();
        $vpnConfig = implode(PHP_EOL, $clientConfig->get(array_merge(['tfa' => $serverInfo['tfa']], $certData['certificate'], $remoteEntities)));

        switch ($type) {
            case 'zip':
                // return a ZIP file    
                $vpnConfigZip = Utils::configToZip($configName, $vpnConfig);
                $response = new Response(200, 'application/zip');
                $response->setHeader('Content-Disposition', sprintf('attachment; filename="%s.zip"', $configName));
                $response->setBody($vpnConfigZip);
                break;
            case 'ovpn':
                // return an OVPN file
                $response = new Response(200, 'application/x-openvpn-profile');
                $response->setHeader('Content-Disposition', sprintf('attachment; filename="%s.ovpn"', $configName));
                $response->setBody($vpnConfig);
                break;
            default:
                throw new BadRequestException('invalid type');
        }

        return $response;
    }

    private function revokeConfig($userId, $configName)
    {
        Utils::validateConfigName($configName);

        $this->vpnConfigApiClient->revokeConfiguration($userId, $configName);

        // trigger a CRL reload in the servers
        $this->vpnServerApiClient->postCrlFetch();

        // disconnect the client
        $this->vpnServerApiClient->postKill(sprintf('%s_%s', $userId, $configName));
    }

    public static function validateOtpSecret($otpSecret)
    {
        if (0 === preg_match('/^[A-Z0-9]{16}$/', $otpSecret)) {
            throw new BadRequestException('invalid OTP secret format');
        }
    }

    public static function validateOtpKey($otpKey)
    {
        if (0 === preg_match('/^[0-9]{6}$/', $otpKey)) {
            throw new BadRequestException('invalid OTP key format');
        }
    }
}
