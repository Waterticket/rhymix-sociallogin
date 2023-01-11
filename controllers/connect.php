<?php

namespace Rhymix\Modules\Sociallogin\Controllers;

use BaseObject;
use Context;
use FileHandler;
use MemberController;
use MemberModel;
use PointController;
use Rhymix\Framework\Exceptions\InvalidRequest;
use Rhymix\Framework\Exception;
use Rhymix\Modules\Sociallogin\Base;
use Rhymix\Modules\Sociallogin\Models\Config as ConfigModel;
use Rhymix\Modules\Sociallogin\Models\User as UserModel;

class Connect extends Base
{
	/**
	 * @brief Callback
	 **/
	public function procSocialloginCallback()
	{
		// 서비스 체크
		if (!($service = Context::get('service')) || !in_array($service, self::getConfig()->sns_services))
		{
			throw new InvalidRequest;
		}
		// 라이브러리 체크
		if (!$oDriver = $this->getDriver($service))
		{
			throw new InvalidRequest;
		}
		
		// 인증 세션 체크
		if (!$_SESSION['sociallogin_auth']['state'])
		{
			throw new InvalidRequest;
		}
		
		// 타입 세션 체크
		if (!$type = $_SESSION['sociallogin_auth']['type'])
		{
			throw new InvalidRequest;
		}
		
		$_SESSION['sociallogin_current']['mid'] = $_SESSION['sociallogin_auth']['mid'];
		$redirect_url = $_SESSION['sociallogin_auth']['redirect'];
		$redirect_url = $redirect_url ? Context::getRequestUri() . '?' . $redirect_url : Context::getRequestUri();

		$request_method = Context::getRequestMethod();
		
		// 인증
		$output = $oDriver->authenticate();
		if ($output instanceof BaseObject && !$output->toBool())
		{
			$error = $output->getMessage();
		}

		// 인증 세션 제거
		unset($_SESSION['sociallogin_auth']);
		
		// SNS정보를 가져옴
		if (!$error)
		{
			$output = $oDriver->getSNSUserInfo();
			if ($output instanceof BaseObject && !$output->toBool())
			{
				$error = $output->getMessage();
				// 오류시 토큰 파기 (롤백)
				$oDriver->revokeToken(self::getDriverAuthData($service)->token['access']);
			}
		}
		
		// 등록 처리
		if (!$error)
		{
			switch($type)
			{
				case 'register':
					$msg = 'msg_success_sns_register';
					
					$output = $this->registerSns($oDriver);
					if (!$output->toBool())
					{
						$error = $output->getMessage();
					}
					break;
				case 'login':
					$output = $this->loginSns($oDriver);
					if (!$output->toBool())
					{
						$error = $output->getMessage();
					}
					
					// 로그인 후 페이지 이동 (회원 설정 참조)
					$redirect_url = getModel('module')->getModuleConfig('member')->after_login_url ?: getNotEncodedUrl('', 'mid', $_SESSION['sociallogin_current']['mid'], 'act', '');
					break;
				case 'recheck':
					$recheckBool = $this->reCheckSns($oDriver, $type);
					if(!$recheckBool)
					{
						$error = lang('sociallogin.msg_invalid_sns_account');
					}
					$redirect_url = getNotEncodedUrl('', 'mid', Context::get('mid'), 'act', 'dispMemberModifyInfo');
					break;
				case 'modify_password':
					$recheckBool = $this->reCheckSns($oDriver, $type);
					if(!$recheckBool)
					{
						$error = lang('sociallogin.msg_invalid_sns_account');
					}
					$redirect_url = getNotEncodedUrl('', 'mid', Context::get('mid'), 'act', 'dispMemberModifyPassword');
					break;
				default:
					$error = lang('sociallogin.msg_not_exist_type');
					break;
			}
		}

		// 로그 기록
		$info = new \stdClass;
		$info->msg = $msg;
		$info->type = $type;
		$info->sns = $service;
		self::logRecord($this->act, $info);
		
		// 오류
		if ($error)
		{
			throw new Exception($error);
		}

		if ($msg)
		{
			$this->setMessage($msg);
		}

		if($request_method != 'XMLRPC' && $request_method != 'JSON')
		{
			if ($type == 'register')
			{
				$this->setRedirectUrl(getNotEncodedUrl('', 'mid', $_SESSION['sociallogin_current']['mid'], 'act', 'dispSocialloginSnsManage'));
			}
			else
			{
				if (!$this->getRedirectUrl())
				{
					$this->setRedirectUrl($redirect_url);
				}
			}
		}
		else
		{
			if ($redirect_url)
			{
				$this->add('redirect_url', $redirect_url);
			}
		}

		return new BaseObject();
	}

	/**
	 * @brief SNS 등록
	 * @param $oDriver \Rhymix\Modules\Sociallogin\Drivers\Base
	 * @param null $member_srl
	 * @param false $login
	 * @return BaseObject|object|self
	 */
	public function registerSns($oDriver, $member_srl = null, $login = false)
	{
		if (!$member_srl)
		{
			$member_srl = Context::get('logged_info')->member_srl;
		}
		$config = self::getConfig();
		if ($config->sns_login != 'Y' && !$member_srl)
		{
			throw new Exception('msg_not_sns_login');
		}

		$service = $oDriver->getService();

		$serviceAccessData = self::getDriverAuthData($service);
		$id = $serviceAccessData->profile['sns_id'];
		if (!$id)
		{
			throw new Exception('msg_errer_api_connect');
		}
		
		// SNS ID 조회
		if (($sns_info = UserModel::getMemberSnsById($id, $service)) && $sns_info->member_srl)
		{
			throw new Exception('msg_already_registed_sns');
		}

		/** @var memberModel $oMemberModel */
		$oMemberModel = memberModel::getInstance();

		// 중복 이메일 계정이 있으면 소셜로그인 중단.
		if (!$member_srl && ($email = $serviceAccessData->profile['email_address']) && !$_SESSION['sociallogin_confirm_email'])
		{
			if ($member_srl = $oMemberModel->getMemberSrlByEmailAddress($email))
			{
				if ($oMemberModel->getMemberInfoByMemberSrl($member_srl)->member_srl)
				{
					throw new Exception('msg_can_not_sns_login_by_email');
				}
			}
		}

		// 회원 가입 진행
		if (!$member_srl)
		{
			$password = \Rhymix\Framework\Password::getRandomPassword(13);
			$nick_name = preg_replace('/[\pZ\pC]+/u', '', $serviceAccessData->profile['user_name']);

			if ($oMemberModel->getMemberSrlByNickName($nick_name))
			{
				$nick_name = $nick_name . date('is');
			}

			$member_config = $oMemberModel::getMemberConfig();
			
			$boolRequired = false;

			foreach ($member_config->signupForm as $item)
			{
				if($item->name == 'user_id')
				{
					continue;
				}
				if($item->name == 'email_address')
				{
					continue;
				}
				if($item->name == 'password')
				{
					continue;
				}
				if($item->name == 'user_name')
				{
					continue;
				}
				if($item->name == 'nick_name')
				{
					continue;
				}
				if($item->required)
				{
					$boolRequired = true;
					break;
				}
			}
			
			if(!$boolRequired && ($member_config->phone_number_verify_by_sms == 'Y' && $config->use_for_phone_auth == 'Y'))
			{
				$boolRequired = true;
			}

			// 미리 소셜 내용 기록.
			$_SESSION['tmp_sociallogin_input_add_info'] = $oDriver->getSocial();
			$_SESSION['tmp_sociallogin_input_add_info']['nick_name'] = $nick_name;
			
			if($email)
			{
				$_SESSION['tmp_sociallogin_input_add_info']['email_address'] = $email;
			}
			// 프로필 이미지를 위한 임시 파일 생성
			if ($oDriver->getProfileImage())
			{
				if (($tmp_dir = 'files/cache/tmp/') && !is_dir($tmp_dir))
				{
					FileHandler::makeDir($tmp_dir);
				}

				$path_parts = pathinfo(parse_url($oDriver->getProfileImage(), PHP_URL_PATH));
				$randomString = \Rhymix\Framework\Security::getRandom(32);
				$tmp_file = "{$tmp_dir}{$randomString}profile.{$path_parts['extension']}";

				if(FileHandler::getRemoteFile($oDriver->getProfileImage(), $tmp_file, null, 3, 'GET', null, array(), array(), array(), array('ssl_verify_peer' => false)))
				{
					$_SESSION['tmp_sociallogin_input_add_info']['profile_dir'] = $tmp_file;
				}
			}
			
			// 회원 정보에서 추가 입력할 데이터가 있을경우 세션값에 소셜정보 입력 후 회원가입 항목으로 이동
			if ($boolRequired)
			{
				$args = new \stdClass;
				$args->refresh_token = $serviceAccessData->token['refresh'];
				// 트위터의 경우 access token 자체가 다른방식으로 저장됨.
				if($oDriver->getService() == 'twitter')
				{
					$args->access_token = $oDriver->getTwitterAccessToken();
				}
				else
				{
					$args->access_token = $serviceAccessData->token['access'];
				}
				$args->profile_info = serialize($serviceAccessData->profile['etc']);
				$args->profile_url = $serviceAccessData->profile['url'];
				$args->profile_image = $serviceAccessData->profile['profile_image'];
				$args->email = $serviceAccessData->profile['email_address'];
				$args->name = $serviceAccessData->profile['user_name'];
				$args->service_id = $serviceAccessData->profile['sns_id'];
				$args->service = $service;
				
				//TODO (BjRambo) :check again, why save to sessionData?
				$_SESSION['sociallogin_access_data'] = $args;
				return $this->setRedirectUrl(getNotEncodedUrl('', 'act', 'dispMemberSignUpForm'));
			}
			
			Context::setRequestMethod('POST');
			Context::set('password', $password, true);
			Context::set('nick_name', $nick_name, true);
			Context::set('user_name', $serviceAccessData->profile['user_name'], true);
			Context::set('email_address', $email, true);
			Context::set('accept_agreement', 'Y', true);

			$extend = $oDriver->getProfileExtend();
			Context::set('homepage', $extend->homepage, true);
			Context::set('blog', $extend->blog, true);
			Context::set('birthday', $extend->birthday, true);
			Context::set('gender', $extend->gender, true);
			Context::set('age', $extend->age, true);
			
			// 회원 모듈에 가입 요청
			// try 를 쓰는이유는 회원가입시 어떤 실패가 일어나는 경우 BaseObject으로 리턴하지 않기에 에러를 출력하기 위함입니다.
			try
			{
				$output = getController('member')->procMemberInsert();
			}
			catch (Exception $exception)
			{
				// 리턴시에도 세션값을 비워줘야함
				unset($_SESSION['tmp_sociallogin_input_add_info']);
				throw new Exception($exception->getMessage());
			}
			unset($_SESSION['tmp_sociallogin_input_add_info']);
			
			// 가입 도중 오류가 있다면 즉시 출력
			if (is_object($output) && method_exists($output, 'toBool') && !$output->toBool())
			{
				if ($output->error != -1)
				{
					// 리턴값을 따로 저장.
					$return_output = $output;
				}
				else
				{
					return $output;
				}
			}

			// 가입 완료 체크
			if (!$member_srl = $oMemberModel->getMemberSrlByEmailAddress($email))
			{
				throw new Exception('msg_error_register_sns');
			}

			// 이전 로그인 기록이 있으면 가입 포인트 제거
			if (UserModel::getSnsUser($id, $service))
			{
				Context::set('__point_message__', Context::getLang('PHC_member_register_sns_login'));

				PointController::getInstance()->setPoint($member_srl, 0, 'update');
			}

			// 서명 등록
			if ($extend->signature)
			{
				MemberController::getInstance()->putSignature($member_srl, $extend->signature);
			}
		}
		// 이미 가입되어 있었다면 SNS 등록만 진행
		else
		{
			// 등록하려는 서비스가 이미 등록되어 있을 경우
			if (($sns_info = UserModel::getMemberSnsByService($service, $member_srl)) && $sns_info->member_srl)
			{
				// 로그인에서 등록 요청이 온 경우 SNS 정보 삭제 후 재등록 (SNS ID가 달라졌다고 판단)
				if ($login)
				{
					$args = new \stdClass;
					$args->service = $service;
					$args->member_srl = $member_srl;
					executeQuery('sociallogin.deleteMemberSns', $args);
				}
				else
				{
					throw new InvalidRequest;
				}
			}
		}

		$args = new \stdClass;
		$args->refresh_token = $serviceAccessData->token['refresh'];
		// 트위터의 경우 access token 자체가 다른방식으로 저장됨.
		if($oDriver->getService() == 'twitter')
		{
			$args->access_token = $oDriver->getTwitterAccessToken();
		}
		else
		{
			$args->access_token = $serviceAccessData->token['access'];
		}
		$args->profile_info = serialize($serviceAccessData->profile['etc']);
		$args->profile_url = $serviceAccessData->profile['url'];
		$args->profile_image = $serviceAccessData->profile['profile_image'];
		$args->email = $serviceAccessData->profile['email_address'];
		$args->name = $serviceAccessData->profile['user_name'];
		$args->service_id = $serviceAccessData->profile['sns_id'];
		$args->service = $service;
		$args->member_srl = $member_srl;
		$output = executeQuery('sociallogin.insertMemberSns', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		// SNS ID 기록 (SNS 정보가 삭제 되더라도 ID는 영구 보관)
		if (!UserModel::getSnsUser($id, $service))
		{
			$output = executeQuery('sociallogin.insertSnsUser', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		self::clearSession();
		// 가입 완료 후 메세지 출력 (메일 인증 메세지)
		if ($return_output)
		{
			return $return_output;
		}

		return new BaseObject();
	}

	/**
	 * @param $member_srl
	 * @param $oDriver \Rhymix\Modules\Sociallogin\Drivers\Base
	 * @return void
	 */
	public function insertMemberSns($member_srl, $oAuthArgs)
	{
		$oAuthArgs->member_srl = $member_srl;
		$return_output = executeQuery('sociallogin.insertMemberSns', $oAuthArgs);

		// SNS ID 기록 (SNS 정보가 삭제 되더라도 ID는 영구 보관)
		if (!UserModel::getSnsUser($oAuthArgs->service_id, $oAuthArgs->service))
		{
			$output = executeQuery('sociallogin.insertSnsUser', $oAuthArgs);
		}
	}

	/**
	 * @brief SNS 로그인
	 * @param $oDriver \Rhymix\Modules\Sociallogin\Drivers\Base
	 * @return BaseObject|object|self
	 */
	public function loginSns($oDriver)
	{
		if (self::getConfig()->sns_login != 'Y')
		{
			throw new Exception('msg_not_sns_login');
		}

		if ($this->user->isMember())
		{
			throw new Exception('already_logged');
		}
		
		$service = $oDriver->getService();
		$serviceAccessData = self::getDriverAuthData($service);
		if (!$serviceAccessData->profile['sns_id'])
		{
			throw new Exception('msg_errer_api_connect');
		}

		// SNS ID로 회원 검색
		$do_login = false;
		if (($sns_info = UserModel::getMemberSnsById($serviceAccessData->profile['sns_id'], $service)) && $sns_info->member_srl)
		{
			// 탈퇴한 회원이면 삭제후 등록 시도
			if (!($member_info = MemberModel::getMemberInfoByMemberSrl($sns_info->member_srl)) || !$member_info->member_srl)
			{
				$args = new \stdClass;
				$args->member_srl = $sns_info->member_srl;
				executeQuery('sociallogin.deleteMemberSns', $args);
			}
			// 로그인 허용
			else
			{
				$do_login = true;
			}
		}
		
		// 검색된 회원으로 로그인 진행
		if ($do_login)
		{
			// 인증 메일
			if ($member_info->denied == 'Y')
			{
				$args = new \stdClass;
				$args->member_srl = $member_info->member_srl;
				$output = executeQuery('member.chkAuthMail', $args);

				if ($output->toBool() && $output->data->count > 0)
				{
					$_SESSION['auth_member_srl'] = $member_info->member_srl;

					return $this->setRedirectUrl(getNotEncodedUrl('', 'act', 'dispMemberResendAuthMail'), new BaseObject(-1, 'msg_user_not_confirmed'));
				}
			}

			// 계정 아이디 셋팅
			if (getModel('member')->getMemberConfig()->identifier == 'email_address')
			{
				$user_id = $member_info->email_address;
			}
			else
			{
				$user_id = $member_info->user_id;
			}

			// 회원 모듈에 로그인 요청
			$output = getController('member')->doLogin($user_id, '', self::getConfig()->sns_keep_signed == 'Y' ? true : false);
			if (!$output->toBool())
			{
				return $output;
			}

			// SNS 세션 등록
			$_SESSION['sns_login'] = $oDriver->getService();

			// 로그인시마다 SNS 회원 정보 갱신
			$args = new \stdClass;
			$args->refresh_token = $serviceAccessData->token['refresh'];
			// 트위터의 경우 access token 자체가 다른방식으로 저장됨.
			if($oDriver->getService() == 'twitter')
			{
				$args->access_token = $oDriver->getTwitterAccessToken();
			}
			else
			{
				$args->access_token = $serviceAccessData->token['access'];
			}
			$args->profile_info = serialize($serviceAccessData->profile['etc']);
			$args->profile_url = $serviceAccessData->profile['url'];
			$args->profile_image = $serviceAccessData->profile['profile_image'];
			$args->email = $serviceAccessData->profile['email_address'];
			if($service !== 'apple')
			{
				$args->name = $serviceAccessData->profile['user_name'];
			}
			$args->service = $oDriver->getService();
			$args->member_srl = $member_info->member_srl;
			$output = executeQuery('sociallogin.updateMemberSns', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}
		// 검색된 회원이 없을 경우 SNS 등록(가입) 요청
		else
		{
			$output = $this->registerSns($oDriver, null, true);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		return new BaseObject();
	}

	/**
	 * @brief SNS recheck
	 * @param $oDriver \Rhymix\Modules\Sociallogin\Drivers\Base
	 * @return Bool
	 */
	public function reCheckSns($oDriver, $type = 'recheck')
	{
		if (!$this->user->isMember())
		{
			throw new InvalidRequest;
		}

		$service = $oDriver->getService();
		$serviceAccessData = self::getDriverAuthData($service);
		if (!$serviceAccessData->profile['sns_id'])
		{
			throw new Exception('msg_errer_api_connect');
		}

		// SNS ID로 회원 검색
		$isCheck = false;
		if (($sns_info = UserModel::getMemberSnsById($serviceAccessData->profile['sns_id'], $service)) && $sns_info->member_srl)
		{
			if($sns_info->service_id == $serviceAccessData->profile['sns_id'])
			{
				$isCheck = true;
			}
		}
		
		if($isCheck)
		{
			if($type == 'recheck')
			{
				$_SESSION['rechecked_password_step'] = 'VALIDATE_PASSWORD';
			}
			else
			{
				$_SESSION['rechecked_password_modify'] = 'VALIDATE_PASSWORD';
			}
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * replace to signup argument.
	 * @param $args
	 * @return object
	 */
	public function replaceSignUpFormBySocial($args)
	{
		$socialLoginUserData = self::getSocialSignUpUserData();

		if($socialLoginUserData)
		{
			$args->nick_name = $args->user_name = $socialLoginUserData->nick_name;
			$args->email_address = $socialLoginUserData->email_address;
		}

		unset($args->user_id);

		// 원래 설정한 비밀번호가 없을 경우 또는 회원가입창으로 넘어가서 정보를 입력 한 경우 해당 password을 새롭게 생성
		if(!$args->password || !$args->password2)
		{
			$args->password = $args->password2 = \Rhymix\Framework\Password::getRandomPassword(13);
		}
		// 원래 설정한 비밀번호가 잇다면 그 비밀번호를 그대로 사용
		else
		{
			$args->password2 = $args->password;
		}
		
		return $args;
	}
	
	/**
	 * 소셜 로그인에 필요한 정보를 세션에서 가져옴
	 * @return false|stdClass
	 */
	public static function getSocialSignUpUserData()
	{
		if(isset($_SESSION['tmp_sociallogin_input_add_info']))
		{
			$return_object = new \stdClass();
			$return_object->nick_name = $_SESSION['tmp_sociallogin_input_add_info']['nick_name'];
			$return_object->email_address = $_SESSION['tmp_sociallogin_input_add_info']['email_address'];
			
			return $return_object;
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * @param $oDriver \Rhymix\Modules\Sociallogin\Drivers\Base
	 * @param $sns_info
	 * @param bool $db
	 */
	public static function setAvailableAccessToken($oDriver, $sns_info, $db = true)
	{
		// 새로고침 토큰이 없을 경우 그대로 넣기
		if (!$sns_info->refresh_token)
		{
			$tokenData = [];
			$tokenData['access'] = $sns_info->access_token;

			return $tokenData;
		}

		// 토큰 새로고침
		$tokenData = $oDriver->refreshToken($sns_info->refresh_token);

		// [실패] 이전 토큰 그대로 넣기
		if (!$tokenData['access'])
		{
			$tokenData['access'] = $sns_info->access_token;
		}
		// [성공] 새로고침된 토큰을 DB에 저장
		else if ($db)
		{
			$args = new \stdClass;
			$args->refresh_token = $tokenData['access'];
			$args->access_token = $tokenData['refresh'];
			$args->service = $oDriver->getService();
			$args->member_srl = $sns_info->member_srl;

			executeQuery('sociallogin.updateMemberSns', $args);
		}
		
		return $tokenData;
	}
}
