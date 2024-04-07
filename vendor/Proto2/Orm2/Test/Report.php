<?php

namespace Proto2\Orm2\Test;

#[Entity]
#[Table( "my_reports" )]
#[Ignore( "privacy", "date_added", "date_updated", "added_by", "updated_by" )]
class Report {

	#[Id]
	public $id = 0;

	#[Rules( "required" )]
	public $site_id = 0;

	#[Rules( "required" )]
    public $id_ref = '';

	#[Rules( "required" )]
    public $report_type = 0;

	public $privacy;
	public $status;
	public $date_added;
	public $date_updated;
	public $added_by = 0;
	public $updated_by = 0;

    #[Relation( model: "reportJob", type: "hasOne", where: "report_id=:id", skipFields: "status", if: "report_type=1" )]
    public $job;

    #[Relation( model: "reportCompany", type: "hasOne", where: "report_id=:id", skipFields: "status" )]
    /**
     * @Test[ one ]
     * @[Relation( { "model": "test", "next": "other" } )]
     * @[Help( model: "reportCompany", type: "hasOne", where: "report_id=:id", skipFields: "status" )]
    **/
    public $company;

}