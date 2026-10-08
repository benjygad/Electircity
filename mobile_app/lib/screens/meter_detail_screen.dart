import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';

class MeterDetailScreen extends StatefulWidget {
  const MeterDetailScreen({super.key, required this.meterId});
  final int meterId;
  @override
  State<MeterDetailScreen> createState() => _MeterDetailScreenState();
}

class _MeterDetailScreenState extends State<MeterDetailScreen> {
  Map<String, dynamic>? _meter;
  CreditInfo? _credit;
  List<Map<String, dynamic>> _readings = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final api = SessionStore.api;
      final m = await api.get('/meters/${widget.meterId}');
      final r = await api.get('/meters/${widget.meterId}/readings?limit=10') as List;
      setState(() {
        _meter = Map<String, dynamic>.from(m);
        _credit = CreditInfo.fromJson(Map<String, dynamic>.from(_meter!['credit']));
        _readings = r.map((e) => Map<String, dynamic>.from(e)).toList();
        _error = null;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final m = _meter;
    return Scaffold(
      appBar: AppBar(title: Text(m?['meter_number'] ?? 'Meter')),
      body: _error != null && m == null
          ? ErrorBox(_error!, onRetry: _load)
          : m == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), children: [
                    Card(
                      child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Container(width: 44, height: 44, decoration: BoxDecoration(color: kNavy, borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.electric_meter, color: Colors.white)),
                          const SizedBox(width: 12),
                          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(m['meter_number'], style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                            Text(m['service_location'], style: const TextStyle(color: kMuted, fontSize: 12.5)),
                          ])),
                          StatusChip('${m['integration_status']}', label: switch ('${m['integration_status']}') {
                            'connected' => 'Connected',
                            'simulated' => 'Simulated',
                            'offline' => 'Offline',
                            _ => 'Integration unavailable',
                          }),
                        ]),
                        const SizedBox(height: 14),
                        _kv('Meter type', '${m['meter_type']}'.replaceAll('_', ' ')),
                        _kv('Latest reading', m['latest_reading'] != null
                            ? '${m['latest_reading']['cumulative_kwh']} kWh @ ${dmyTime(DateTime.tryParse('${m['latest_reading']['reading_time']}Z') ?? DateTime.now())} (source: ${m['latest_reading']['source']})'
                            : 'No readings yet'),
                        _kv('Reading freshness', '${m['reading_freshness'] ?? 'unknown'}'),
                      ])),
                    ),
                    if (_credit != null) ...[
                      const SectionTitle('Prepaid credit (estimate)'),
                      Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tzs(_credit!.credit), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 24, color: _credit!.credit < 2000 ? kRed : kGreen)),
                        const SizedBox(height: 6),
                        _kv('Total purchases', tzs(_credit!.purchases)),
                        _kv('Units consumed', kwh(_credit!.units)),
                        _kv('Demo tariff', 'TZS ${_credit!.tariff.toInt()} per kWh'),
                        const SizedBox(height: 8),
                        const Text('Estimate only: purchases − (units × demo tariff). Tariff is an unverified demonstration assumption.',
                            style: TextStyle(fontSize: 11, color: kMuted)),
                      ]))),
                    ],
                    const SectionTitle('Recent readings'),
                    if (_readings.isEmpty) const EmptyState(Icons.speed, 'No readings recorded for this meter.'),
                    for (final r in _readings) Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        dense: true,
                        leading: const Icon(Icons.speed_outlined, color: kTeal),
                        title: Text('${r['cumulative_kwh']} kWh cumulative', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                        subtitle: Text('${dmyTime(DateTime.tryParse('${r['reading_time']}Z') ?? DateTime.now())} · source: ${r['source']}', style: const TextStyle(fontSize: 11.5)),
                      ),
                    ),
                  ]),
                ),
    );
  }

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 130, child: Text(k, style: const TextStyle(color: kMuted, fontSize: 12.5))),
          Expanded(child: Text(v, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5))),
        ]),
      );
}
