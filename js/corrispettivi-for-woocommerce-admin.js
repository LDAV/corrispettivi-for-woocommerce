jQuery(function ($) {
	var config = window.corrispettiviForWooCommerceData || {};
	var exportTableData = config.export_payload || {};

	if (config.selected_month) {
		$("#corrispettivi_for_woocommerce_select").val(config.selected_month);
	}
	$("#corrispettivi_for_woocommerce_show_0_days").prop("checked", !!config.show_zero_days);

	$(document).on("click", ".corrispettivi_for_woocommerce button.notice-dismiss", function () {
		if (!config.ajax_url || !config.dismiss_nonce) {
			return;
		}
		$.post(config.ajax_url, {
			action: "corrispettivi_for_woocommerce_dismiss_notice",
			_wpnonce: config.dismiss_nonce
		});
	});

	function getAoa(forXlsx) {
		var columns = exportTableData.columns || [];
		var rows = exportTableData.rows || [];
		var header = columns.map(function (col) {
			return col.label;
		});
		var aoa = [header];

		rows.forEach(function (row) {
			var line = columns.map(function (col) {
				var value = Object.prototype.hasOwnProperty.call(row, col.key) ? row[col.key] : "";
				if (col.type === "date") {
					if (!value) {
						return "";
					}
					return forXlsx ? new Date(value + "T00:00:00") : String(value);
				}
				if (col.type === "number") {
					if (value === "" || value === null || typeof value === "undefined") {
						return "";
					}
					return Number(value);
				}
				return value === null || typeof value === "undefined" ? "" : String(value);
			});
			aoa.push(line);
		});

		return aoa;
	}

	function getSheet(forXlsx) {
		var ws = XLSX.utils.aoa_to_sheet(getAoa(forXlsx), { cellDates: true });
		var columns = exportTableData.columns || [];
		var rows = exportTableData.rows || [];

		columns.forEach(function (col, index) {
			if (col.type !== "date") {
				return;
			}
			for (var rowIdx = 1; rowIdx <= rows.length; rowIdx++) {
				var ref = XLSX.utils.encode_cell({ r: rowIdx, c: index });
				if (ws[ref] && ws[ref].v) {
					ws[ref].z = "yyyy-mm-dd";
				}
			}
		});
		return ws;
	}

	function exportWorkbook(bookType, extension) {
		if (!window.XLSX || !exportTableData.columns || !exportTableData.rows) {
			return;
		}
		var wb = XLSX.utils.book_new();
		var ws = getSheet(bookType === "xlsx");
		XLSX.utils.book_append_sheet(wb, ws, exportTableData.sheet || "Corrispettivi");
		XLSX.writeFile(wb, (exportTableData.filename || "corrispettivi") + "." + extension, { bookType: bookType });
	}

	$("#corrispettivi_for_woocommerce_export_xlsx").on("click", function () {
		exportWorkbook("xlsx", "xlsx");
	});

	$("#corrispettivi_for_woocommerce_export_csv").on("click", function () {
		exportWorkbook("csv", "csv");
	});
});
