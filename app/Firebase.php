<?php
declare(strict_types=1);

final class Firebase {
    private array $config;
    public function __construct() { $this->config = require __DIR__.'/firebase-config.php'; }

    public static function request(string $method, string $url, ?array $data = null, ?string $token = null): array {
        $curl = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25]);
        if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($result === false) throw new RuntimeException('Firebase could not be reached. Please try again.');
        $body = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            $code = $body['error']['message'] ?? '';
            error_log('Firebase '.basename(parse_url($url, PHP_URL_PATH)).' HTTP '.$status.': '.$code);
            $message = match (true) {
                str_contains($code, 'EMAIL_EXISTS') => 'That email is already registered. Sign in instead.',
                str_contains($code, 'OPERATION_NOT_ALLOWED') => 'Email/password sign-in has not been enabled in Firebase.',
                str_contains($code, 'TOO_MANY_ATTEMPTS') => 'Too many attempts. Please try again later.',
                $status === 404 => 'Record not found.',
                $status === 409 => 'This report changed in another window. Refresh and try again.',
                $status === 403 => 'Firebase denied this request. Check the project access rules.',
                $status === 401 => 'Your sign-in has expired. Please sign out and sign in again.',
                str_contains($url, 'identitytoolkit.googleapis.com') => 'Unable to sign in. Check your email and password.',
                default => 'Firebase could not save or load the records. Please try again.',
            };
            throw new RuntimeException($message);
        }
        return $body ?? [];
    }

    public function authenticate(string $email, string $password, bool $register): array {
        $method = $register ? 'signUp' : 'signInWithPassword';
        $result = self::request('POST', 'https://identitytoolkit.googleapis.com/v1/accounts:'.$method.'?key='.$this->config['api_key'],
            ['email'=>$email,'password'=>$password,'returnSecureToken'=>true]);
        return ['user_id'=>$result['localId'],'username'=>$result['email'],
            'firebase_token'=>$result['idToken'],'firebase_refresh'=>$result['refreshToken'],
            'firebase_expires'=>time()+(int)$result['expiresIn']];
    }

    public function token(): string {
        if (!isset($_SESSION['firebase_token'])) throw new RuntimeException('Please sign in first.');
        if (time() >= ($_SESSION['firebase_expires'] ?? 0)-60) {
            $url='https://securetoken.googleapis.com/v1/token?key='.$this->config['api_key'];
            $result=self::request('POST',$url,['grant_type'=>'refresh_token','refresh_token'=>$_SESSION['firebase_refresh']]);
            $_SESSION['firebase_token']=$result['id_token'];
            $_SESSION['firebase_refresh']=$result['refresh_token'];
            $_SESSION['firebase_expires']=time()+(int)$result['expires_in'];
        }
        return $_SESSION['firebase_token'];
    }

    public function databaseName(): string { return 'projects/'.$this->config['project_id'].'/databases/(default)'; }
    public function documentRoot(): string { return $this->databaseName().'/documents'; }
    public function api(string $method, string $resource, ?array $data=null): array {
        return self::request($method,'https://firestore.googleapis.com/v1/'.$resource,$data,$this->token());
    }
    public static function fields(array $data): array {
        return array_map(fn($value)=>['stringValue'=>(string)$value],$data);
    }
    public static function document(array $doc): array {
        $data=[];
        foreach ($doc['fields']??[] as $key=>$value) $data[$key]=$value['stringValue']??'';
        $data['id']=basename($doc['name']);
        $data['_version']=$doc['updateTime']??'';
        return $data;
    }
}

final class FirebaseLostFoundItem {
    private string $root;
    private const FIELDS=['item_name','category','item_type','description','location','date_reported','status','reporter_name'];
    public function __construct(private Firebase $firebase, private string $userId) {
        $this->root=$firebase->documentRoot().'/users/'.rawurlencode($userId);
    }
    private function itemPath(string $id): string {
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) throw new RuntimeException('Record not found.');
        return $this->root.'/items/'.$id;
    }
    public function find(string $id, ?string $transaction=null): array {
        $path=$this->itemPath($id);
        if ($transaction !== null) $path.='?transaction='.rawurlencode($transaction);
        return Firebase::document($this->firebase->api('GET',$path));
    }
    private function change(string $action, ?string $id, ?array $data): void {
            $id ??= bin2hex(random_bytes(12));
            $name=$this->itemPath($id);
            $old=$action==='Added'?null:$this->find($id);
            $reference=$old['reference_no']??'LNF-'.strtoupper(substr($id,0,12));
            if ($action==='Deleted') {
                $write=['delete'=>$name,'currentDocument'=>['updateTime'=>$old['_version']]];
            } else {
                $fields=array_intersect_key($data,array_flip(self::FIELDS));
                $fields['reference_no']=$reference;
                $fields['created_at']=$old['created_at']??gmdate('Y-m-d H:i:s');
                $write=['update'=>['name'=>$name,'fields'=>Firebase::fields($fields)],
                    'currentDocument'=>$old?['updateTime'=>$old['_version']]:['exists'=>false]];
            }
            $logName=$this->root.'/activity/'.bin2hex(random_bytes(12));
            $log=['update'=>['name'=>$logName,'fields'=>Firebase::fields([
                'action'=>$action,'reference_no'=>$reference,'created_at'=>gmdate('Y-m-d H:i:s')])],
                'currentDocument'=>['exists'=>false]];
            $this->firebase->api('POST',$this->firebase->documentRoot().':commit',
                ['writes'=>[$write,$log]]);
    }
    public function add(array $data): void { $this->change('Added',null,$data); }
    public function update(string $id,array $data): void { $this->change('Updated',$id,$data); }
    public function delete(string $id): void { $this->change('Deleted',$id,null); }
    private function all(string $collection): array {
        $records=[]; $page='';
        do {
            $url=$this->root.'/'.$collection.'?pageSize=100';
            if ($page!=='') $url.='&pageToken='.rawurlencode($page);
            $response=$this->firebase->api('GET',$url);
            foreach ($response['documents']??[] as $doc) $records[]=Firebase::document($doc);
            $page=$response['nextPageToken']??'';
        } while ($page!=='');
        usort($records,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        return $records;
    }
    public function search(string $keyword): array {
        $records=$this->all('items');
        if ($keyword==='') return $records;
        return array_values(array_filter($records,function($row) use ($keyword) {
            foreach (['reference_no','item_name','category','item_type','location','status','reporter_name'] as $field) {
                if (stripos($row[$field],$keyword)!==false) return true;
            }
            return false;
        }));
    }
    public function history(): array {
        $response=$this->firebase->api('POST',$this->root.':runQuery',['structuredQuery'=>[
            'from'=>[['collectionId'=>'activity']], 'orderBy'=>[['field'=>['fieldPath'=>'created_at'],'direction'=>'DESCENDING']], 'limit'=>15]]);
        $records=[];
        foreach ($response as $entry) if (isset($entry['document'])) $records[]=Firebase::document($entry['document']);
        return $records;
    }
}
