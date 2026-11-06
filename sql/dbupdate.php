<#1>
<?php
// Plugin configuration (key/value).
if (!$ilDB->tableExists('rep_robj_xtar_cfg')) {
    $ilDB->createTable('rep_robj_xtar_cfg', [
        'cfg_key' => ['type' => 'text', 'length' => 100, 'notnull' => true],
        'cfg_value' => ['type' => 'clob', 'notnull' => false],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_cfg', ['cfg_key']);
}
?>
<#2>
<?php
// Settings of one Tarsus object.
if (!$ilDB->tableExists('rep_robj_xtar_data')) {
    $ilDB->createTable('rep_robj_xtar_data', [
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'is_online' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
        'conv_style' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 3],
        'dqr' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 6],
        'industry' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'tax_levels' => ['type' => 'text', 'length' => 50, 'notnull' => false],
        'q_types' => ['type' => 'text', 'length' => 1000, 'notnull' => false],
        'operators' => ['type' => 'text', 'length' => 20, 'notnull' => false],
        'anticheat' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
        'learn_tempo' => ['type' => 'float', 'notnull' => true, 'default' => 1],
        'ask_on' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 1],
        'test_on' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 1],
        'build_on' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 1],
        'gif_on' => ['type' => 'integer', 'length' => 1, 'notnull' => true, 'default' => 0],
        'learn_method' => ['type' => 'text', 'length' => 50, 'notnull' => false],
        'compl_progress' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 80],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_data', ['obj_id']);
}
?>
<#3>
<?php
// The index map: one row per bit the brain holds or is about to hold.
if (!$ilDB->tableExists('rep_robj_xtar_idx')) {
    $ilDB->createTable('rep_robj_xtar_idx', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'resource_id' => ['type' => 'text', 'length' => 255, 'notnull' => true],
        'asset_id' => ['type' => 'text', 'length' => 255, 'notnull' => true],
        'container_ref' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'src_type' => ['type' => 'text', 'length' => 10, 'notnull' => true],
        'src_ref' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'content_hash' => ['type' => 'text', 'length' => 64, 'notnull' => false],
        'bit_ref' => ['type' => 'text', 'length' => 255, 'notnull' => false],
        'idx_status' => ['type' => 'text', 'length' => 32, 'notnull' => true],
        'attempts' => ['type' => 'integer', 'length' => 2, 'notnull' => true, 'default' => 0],
        'next_retry' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'created' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'modified' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_idx', ['id']);
    $ilDB->addUniqueConstraint('rep_robj_xtar_idx', ['resource_id'], 'u1');
    $ilDB->addIndex('rep_robj_xtar_idx', ['container_ref'], 'i1');
    $ilDB->addIndex('rep_robj_xtar_idx', ['idx_status'], 'i2');
    $ilDB->createSequence('rep_robj_xtar_idx');
}
?>
<#4>
<?php
// Which indexed material an object may use.
if (!$ilDB->tableExists('rep_robj_xtar_inc')) {
    $ilDB->createTable('rep_robj_xtar_inc', [
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'resource_id' => ['type' => 'text', 'length' => 255, 'notnull' => true],
        'created' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_inc', ['obj_id', 'resource_id']);
    $ilDB->addIndex('rep_robj_xtar_inc', ['resource_id'], 'i1');
}
?>
<#5>
<?php
// The latest figures the brain reported per learner.
if (!$ilDB->tableExists('rep_robj_xtar_prg')) {
    $ilDB->createTable('rep_robj_xtar_prg', [
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'usr_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'earned_xp' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'total_xp' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'earned_units' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'total_units' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'practice_prg' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'learn_prg' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'overall_prg' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'modified' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_prg', ['obj_id', 'usr_id']);
}
?>
<#6>
<?php
// Usage ledger (credits per operation).
if (!$ilDB->tableExists('rep_robj_xtar_use')) {
    $ilDB->createTable('rep_robj_xtar_use', [
        'id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'container_ref' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'obj_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'usr_id' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'op_name' => ['type' => 'text', 'length' => 50, 'notnull' => true],
        'credits' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'created' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_use', ['id']);
    $ilDB->addIndex('rep_robj_xtar_use', ['created'], 'i1');
    $ilDB->addIndex('rep_robj_xtar_use', ['obj_id', 'usr_id'], 'i2');
    $ilDB->createSequence('rep_robj_xtar_use');
}
?>
<#7>
<?php
// Guardrail state (circuit breaker, budget, token bucket).
if (!$ilDB->tableExists('rep_robj_xtar_grd')) {
    $ilDB->createTable('rep_robj_xtar_grd', [
        'g_name' => ['type' => 'text', 'length' => 32, 'notnull' => true],
        'g_state' => ['type' => 'text', 'length' => 16, 'notnull' => true],
        'failures' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'open_since' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'tokens' => ['type' => 'float', 'notnull' => true, 'default' => 0],
        'last_refill' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'credits_used' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'period_start' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
        'modified' => ['type' => 'integer', 'length' => 4, 'notnull' => true, 'default' => 0],
    ]);
    $ilDB->addPrimaryKey('rep_robj_xtar_grd', ['g_name']);
}
?>
<#8>
<?php
// Interaction type per object: trainer-driven (text and pictures) or
// ai-driven (interactive pages), plan permitting.
if ($ilDB->tableExists('rep_robj_xtar_data') && !$ilDB->tableColumnExists('rep_robj_xtar_data', 'interaction')) {
    $ilDB->addTableColumn('rep_robj_xtar_data', 'interaction', [
        'type' => 'text', 'length' => 32, 'notnull' => true, 'default' => 'trainer-driven',
    ]);
}
?>
