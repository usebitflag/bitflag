<?php

declare(strict_types=1);

namespace App\Shared\Ids;

/**
 * Type prefixes of public identifiers, for example `prj_01j9x3…`. The prefix tells a person and the code which kind
 * of object an identifier names; the rest is the lowercase ULID primary key. Secret prefixes (`bf_srv_`, `bf_pub_`,
 * `bf_mgt_`, `bf_pat_`, `whsec_`) are not identifiers and do not belong here.
 */
enum IdPrefix: string
{
    case Organization = 'org_';
    case User = 'usr_';
    case Project = 'prj_';
    case Environment = 'env_';
    case Flag = 'flg_';
    case Variation = 'var_';
    case Segment = 'seg_';
    case Change = 'chg_';
    case Revision = 'rev_';
    case Key = 'key_';
    case Experiment = 'exp_';
    case Webhook = 'whk_';
    case Event = 'evt_';
    case AuditEntry = 'aud_';
    case Request = 'req_';
    case Rule = 'rule_';
    case Override = 'ovr_';
    case SegmentVersion = 'sgv_';
    case VariationValue = 'vvl_';
    case TestContext = 'tcx_';
    case ReviewCandidate = 'cnd_';
    case Approval = 'apv_';
    case WebhookDelivery = 'dlv_';
    case DeliveryAttempt = 'att_';
    case ProviderConnection = 'int_';
    case ExperimentAllocation = 'alc_';
    case MetricDefinition = 'met_';
}
