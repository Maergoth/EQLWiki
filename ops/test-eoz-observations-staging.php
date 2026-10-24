<?php
/** Explicit staging-only real repository transaction; always rolled back. */
use EQLClientData\ObservationPayload;
use EQLClientData\ObservationRepository;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\IDatabase;
class EQLTestStagingObservations extends Maintenance {
    public function __construct() {
        parent::__construct();$this->addDescription( 'Explicit staging-only observation storage acceptance in a rolled-back outer transaction. Never run on production.' );
        $this->addOption( 'expected-db','Exact reviewed staging database name.',true,true );
        $this->addOption( 'user','Existing named operator for server-derived actor attribution; never creates an account.',true,true );
        $this->addOption( 'transaction-test','Explicitly authorize the temporary staging transaction and guaranteed rollback.',true,false );
    }
    public function execute() {
        $services=$this->getServiceContainer();$config=$services->getMainConfig();$db=$services->getConnectionProvider()->getPrimaryDatabase();
        if ( $config->get('Server')!=='https://test.eqlwiki.com'||$db->getType()!=='mysql'||$db->getDBname()!==$this->getOption('expected-db')||!$this->hasOption('transaction-test') ) { $this->fatalError('Only the exact reviewed staging database may run this explicit rollback test.'); }
        $user=$services->getUserFactory()->newFromName((string)$this->getOption('user'));
        if ( !$user||!$user->isNamed()||!$user->getId() ) { $this->fatalError('The reviewed operator account must already exist.'); }
        $repository=$services->getService('EQLClientData.ObservationRepository');
        if ( !$repository->enabled()||!$repository->available() ) { $this->fatalError('Reviewed schema and collection must already be activated on staging.'); }
        $check=static function($condition,$message) { if(!$condition)throw new RuntimeException($message); };
        $counts=static function()use($db) { $counts=[];foreach(ObservationRepository::TABLES as $table)$counts[$table]=(int)$db->selectField($table,'COUNT(*)',[],__METHOD__);$state=$db->selectRow('eql_eoz_state',['eos_events','eos_batches'],['eos_id'=>1],__METHOD__);return ['counts'=>$counts,'state'=>['events'=>(int)$state->eos_events,'batches'=>(int)$state->eos_batches]]; };
        $nonce=bin2hex(random_bytes(12));$eventId='staging_acceptance_'.$nonce;$batchId='staging_batch_'.$nonce;
        $payload=['schemaVersion'=>1,'batchId'=>$batchId,'clientVersion'=>'staging-rollback-acceptance','events'=>[['id'=>$eventId,'kind'=>'loot','observedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'zone'=>'Synthetic staging zone','npc'=>'Synthetic staging monster','item'=>'Synthetic staging item','quantity'=>1,'sourceKind'=>'explicit-corpse','instance'=>'normal']]];
        $before=null;$checks=0;$db->startAtomic(__METHOD__,IDatabase::ATOMIC_CANCELABLE);
        try {
            $db->newSelectQueryBuilder()->select('eos_id')->from('eql_eoz_state')->where(['eos_id'=>1])->forUpdate()->caller(__METHOD__)->fetchRow();
            $before=$counts();$batch=ObservationPayload::batch(json_encode($payload,JSON_THROW_ON_ERROR));$first=$repository->store($user,$batch);
            $check($first['accepted']===[$eventId]&&$first['account']['id']===$user->getId(),'Actual actor attribution/new event acceptance failed.');$checks++;
            $same=$repository->store($user,$batch);$check($same['accepted']===[]&&$same['duplicates']===[$eventId],'Actual batch replay was not idempotent.');$checks++;
            $payload['batchId']='staging_conflict_'.$nonce;$payload['events'][0]['quantity']=2;$changed=$repository->store($user,ObservationPayload::batch(json_encode($payload,JSON_THROW_ON_ERROR)));
            $check($changed['conflicts']===[$eventId]&&$changed['accepted']===[],'Changed same-ID payload must conflict without overwriting.');$checks++;
            $during=$counts();$check($during['state']['events']===$before['state']['events']+1&&$during['state']['batches']===$before['state']['batches']+2,'Actual state counters do not match temporary writes.');$checks++;
        } finally { $db->cancelAtomic(__METHOD__); }
        $after=$counts();$check($before!==null&&$after===$before,'Outer rollback did not restore all three table counts/counters.');$checks++;
        $check(!(bool)$db->selectField('eql_eoz_observations','eoo_id',['eoo_key'=>$eventId],__METHOD__),'Temporary observation survived rollback.');$checks++;
        $this->output(json_encode(['schemaVersion'=>1,'checks'=>$checks,'staging'=>true,'outerTransactionRolledBack'=>true,'before'=>$before,'after'=>$after,'articleWrites'=>0,'uploads'=>0,'accountWrites'=>0,'authenticatedHttpLoginTest'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    }
}
return EQLTestStagingObservations::class;
