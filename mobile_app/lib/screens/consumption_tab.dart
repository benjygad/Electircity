import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';

class ConsumptionTab extends StatefulWidget {
  const ConsumptionTab({super.key});
  @override
  State<ConsumptionTab> createState() => _ConsumptionTabState();
}

class _ConsumptionTabState extends State<ConsumptionTab> {
  List<Meter> _meters = [];
  Meter? _selected;
  String _range = '30'; // days: 7, 30, 90
  ConsumptionSummary? _summary;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    try {
      final list = await SessionStore.api.get('/meters') as List;
      _meters = list.map((e) => Meter.fromJson(Map<String, dynamic>.from(e))).toList();
      if (_meters.isNotEmpty) {
        _selected = _meters.first;
        await _load();
      } else {
        setState(() => _loading = false);
      }
    } catch (e) {
      setState(() { _loading = false; _error = 'Could not load meters.'; });
    }
  }

  Future<void> _load() async {
    if (_selected == null) return;
    setState(() { _loading = true; _error = null; });
    try {
      final days = int.parse(_range);
      final to = DateTime.now().toUtc().toIso8601String().substring(0, 10);
      final from = DateTime.now().toUtc().subtract(Duration(days: days)).toIso8601String().substring(0, 10);
      final data = await SessionStore.api.get('/meters/${_selected!.id}/consumption?from=$from&to=$to');
      setState(() { _summary = ConsumptionSummary.fromJson(Map<String, dynamic>.from(data)); _loading = false; });
    } on ApiException catch (e) {
      setState(() { _error = e.message; _loading = false; });
    } catch (_) {
      setState(() { _error = 'Could not reach the SEMS server.'; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Consumption monitoring')),
      body: _meters.isEmpty && !_loading
          ? ListView(children: const [SimBanner(), SizedBox(height: 40), EmptyState(Icons.electric_meter, 'No meter linked to your account yet.')])
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), physics: const AlwaysScrollableScrollPhysics(), children: [
                const SimBanner(),
                const SizedBox(height: 10),
                if (_meters.length > 1) ...[
                  DropdownButtonFormField<Meter>(
                    value: _selected,
                    decoration: const InputDecoration(labelText: 'Meter'),
                    items: _meters.map((m) => DropdownMenuItem(value: m, child: Text('${m.meterNumber} — ${m.serviceLocation}'))).toList(),
                    onChanged: (m) { setState(() => _selected = m); _load(); },
                  ),
                  const SizedBox(height: 10),
                ],
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: '7', label: Text('7 days')),
                    ButtonSegment(value: '30', label: Text('30 days')),
                    ButtonSegment(value: '90', label: Text('90 days')),
                  ],
                  selected: {_range},
                  onSelectionChanged: (s) { setState(() => _range = s.first); _load(); },
                ),
                const SizedBox(height: 14),
                if (_error != null) ErrorBox(_error!, onRetry: _load),
                if (_loading) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
                if (!_loading && _summary != null) ...[
                  Row(children: [
                    Expanded(child: StatCard(icon: Icons.bolt, label: 'Energy used', value: kwh(_summary!.totalKwh))),
                    const SizedBox(width: 10),
                    Expanded(child: StatCard(icon: Icons.payments_outlined, label: 'Estimated cost', value: tzs(_summary!.estimatedCostTzs), color: Colors.deepPurple)),
                  ]),
                  Padding(
                    padding: const EdgeInsets.only(top: 6, left: 2),
                    child: Text('Cost uses the demo tariff assumption (TZS ${_summary!.tariff.toInt()}/kWh) — not a verified TANESCO tariff.',
                        style: const TextStyle(fontSize: 10.5, color: kMuted)),
                  ),
                  const SectionTitle('Daily consumption'),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(8, 18, 16, 8),
                      child: SizedBox(
                        height: 210,
                        child: _summary!.series.isEmpty
                            ? const EmptyState(Icons.show_chart, 'No consumption recorded in this period.')
                            : BarChart(BarChartData(
                                alignment: BarChartAlignment.spaceAround,
                                maxY: _summary!.series.map((e) => e.kwh).reduce((a, b) => a > b ? a : b) * 1.25,
                                gridData: FlGridData(drawVerticalLine: false, getDrawingHorizontalLine: (v) => FlLine(color: kLine, strokeWidth: .8)),
                                titlesData: FlTitlesData(
                                  leftTitles: AxisTitles(sideTitles: SideTitles(showTitles: true, reservedSize: 34, getTitlesWidget: (v, t) => Text('${v.toInt()}', style: const TextStyle(fontSize: 9.5, color: kMuted)))),
                                  bottomTitles: AxisTitles(sideTitles: SideTitles(showTitles: true, interval: (_summary!.series.length / 6).clamp(1, 30),
                                      getTitlesWidget: (v, t) {
                                    final i = v.toInt();
                                    if (i < 0 || i >= _summary!.series.length) return const SizedBox.shrink();
                                    final d = _summary!.series[i].day;
                                    return Padding(padding: const EdgeInsets.only(top: 6), child: Text(d.substring(8, 10), style: const TextStyle(fontSize: 9.5, color: kMuted)));
                                  })),
                                  topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                                  rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                                ),
                                borderData: FlBorderData(show: false),
                                barGroups: _summary!.series.asMap().entries.map((e) => BarChartGroupData(x: e.key, barRods: [
                                  BarChartRodData(toY: e.value.kwh, width: 12, borderRadius: BorderRadius.circular(4), color: kTeal),
                                ])).toList(),
                                barTouchData: BarTouchData(touchTooltipData: BarTouchTooltipData(
                                  getTooltipItem: (g, i, r, _) => BarTooltipItem('${g.x + 1 > _summary!.series.length ? '' : _summary!.series[g.x].day}\n${r.toY.toStringAsFixed(1)} kWh',
                                      const TextStyle(color: Colors.white, fontSize: 11)),
                                )),
                              )),
                      ),
                    ),
                  ),
                  const SectionTitle('Reading source & status'),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(children: [
                        StatusChip(_selected!.integrationStatus, label: _selected!.statusLabel),
                        const SizedBox(width: 10),
                        Expanded(child: Text(
                          _selected!.integrationStatus == 'simulated'
                              ? 'Readings are generated by the SEMS simulator for demonstration.'
                              : _selected!.integrationStatus == 'connected'
                                  ? 'Readings arrive through the meter integration gateway.'
                                  : 'No live reading source is available for this meter.',
                          style: const TextStyle(fontSize: 12, color: kMuted)),
                        ),
                      ]),
                    ),
                  ),
                ],
              ]),
            ),
    );
  }
}
