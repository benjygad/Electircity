/// Lightweight models over the SEMS JSON API.

class Meter {
  Meter.fromJson(Map<String, dynamic> j)
      : id = j['id'] is int ? j['id'] : int.parse('${j['id']}'),
        meterNumber = j['meter_number'] ?? '',
        meterType = j['meter_type'] ?? '',
        serviceLocation = j['service_location'] ?? '',
        integrationStatus = j['integration_status'] ?? 'integration_unavailable',
        relationship = j['relationship'],
        lastReadingAt = j['last_reading_at'],
        readingFreshness = j['reading_freshness'];

  final int id;
  final String meterNumber;
  final String meterType;
  final String serviceLocation;
  final String integrationStatus;
  final String? relationship;
  final String? lastReadingAt;
  final String? readingFreshness;

  String get statusLabel => switch (integrationStatus) {
        'connected' => 'Connected',
        'simulated' => 'Simulated',
        'offline' => 'Offline',
        _ => 'Integration unavailable',
      };
}

class TokenTransaction {
  TokenTransaction.fromJson(Map<String, dynamic> j)
      : id = j['id'],
        amount = (j['amount'] is num) ? (j['amount'] as num).toDouble() : double.tryParse('${j['amount']}') ?? 0,
        tokenReference = j['token_reference'],
        status = j['status'] ?? 'recorded',
        source = j['source'] ?? 'simulated',
        createdAt = DateTime.tryParse('${j['created_at']}') ?? DateTime.now();

  final int id;
  final double amount;
  final String? tokenReference;
  final String status;
  final String source;
  final DateTime createdAt;
}

class ConsumptionPoint {
  ConsumptionPoint.fromJson(Map<String, dynamic> j)
      : day = j['day'] ?? '',
        kwh = (j['kwh'] is num) ? (j['kwh'] as num).toDouble() : 0;
  final String day;
  final double kwh;
}

class ConsumptionSummary {
  ConsumptionSummary.fromJson(Map<String, dynamic> j)
      : totalKwh = (j['total_kwh'] is num) ? (j['total_kwh'] as num).toDouble() : 0,
        estimatedCostTzs = (j['estimated_cost_tzs'] is num) ? (j['estimated_cost_tzs'] as num).toDouble() : 0,
        tariff = (j['tariff_per_kwh_tzs'] is num) ? (j['tariff_per_kwh_tzs'] as num).toDouble() : 0,
        series = ((j['series'] as List?) ?? []).map((e) => ConsumptionPoint.fromJson(Map<String, dynamic>.from(e))).toList();
  final double totalKwh;
  final double estimatedCostTzs;
  final double tariff;
  final List<ConsumptionPoint> series;
}

class CreditInfo {
  CreditInfo.fromJson(Map<String, dynamic> j)
      : credit = (j['estimated_credit_tzs'] is num) ? (j['estimated_credit_tzs'] as num).toDouble() : 0,
        purchases = (j['total_purchases_tzs'] is num) ? (j['total_purchases_tzs'] as num).toDouble() : 0,
        units = (j['total_units_consumed_kwh'] is num) ? (j['total_units_consumed_kwh'] as num).toDouble() : 0,
        tariff = (j['tariff_per_kwh_tzs'] is num) ? (j['tariff_per_kwh_tzs'] as num).toDouble() : 0;
  final double credit;
  final double purchases;
  final double units;
  final double tariff;
}

class FaultReport {
  FaultReport.fromJson(Map<String, dynamic> j)
      : id = j['id'],
        category = j['category'] ?? 'other',
        description = j['description'] ?? '',
        status = j['status'] ?? 'submitted',
        meterNumber = j['meter_number'],
        createdAt = DateTime.tryParse('${j['created_at']}') ?? DateTime.now();

  final int id;
  final String category;
  final String description;
  final String status;
  final String? meterNumber;
  final DateTime createdAt;

  String get categoryLabel => switch (category) {
        'power_outage' => 'Power outage',
        'meter_problem' => 'Meter problem',
        'suspected_incorrect_reading' => 'Suspected incorrect reading',
        'supply_issue' => 'Supply issue',
        _ => 'Other',
      };
  String get statusLabel => status.replaceAll('_', ' ');
}

class SupportMessage {
  SupportMessage.fromJson(Map<String, dynamic> j)
      : id = j['id'],
        senderId = j['sender_id'],
        senderName = j['sender_name'] ?? 'Support',
        senderRole = j['sender_role'] ?? 'consumer',
        message = j['message'] ?? '',
        createdAt = DateTime.tryParse('${j['created_at']}') ?? DateTime.now();
  final int id;
  final int senderId;
  final String senderName;
  final String senderRole;
  final String message;
  final DateTime createdAt;
}

class AppNotification {
  AppNotification.fromJson(Map<String, dynamic> j)
      : id = j['id'],
        title = j['title'] ?? '',
        message = j['message'] ?? '',
        readAt = j['read_at'] == null ? null : DateTime.tryParse('${j['read_at']}'),
        createdAt = DateTime.tryParse('${j['created_at']}') ?? DateTime.now();
  final int id;
  final String title;
  final String message;
  final DateTime? readAt;
  final DateTime createdAt;
  bool get isRead => readAt != null;
}
