import React from 'react'
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip
} from 'recharts'

const defaultFormatter = (value) => value

export default function TrendChart({
  data,
  dataKey,
  color,
  label,
  height = 260,
  valueFormatter = defaultFormatter
}) {
  if (!Array.isArray(data) || data.length === 0) {
    return null
  }

  return (
    <div className='mumb-health-chart'>
      <h3>{label}</h3>
      <div className='mumb-health-chart__canvas'>
        <ResponsiveContainer width='100%' height={height}>
          <LineChart data={data} margin={{ top: 16, right: 24, bottom: 8, left: 0 }}>
            <CartesianGrid strokeDasharray='4 4' stroke='rgba(148, 163, 184, 0.35)' />
            <XAxis
              dataKey='label'
              tick={{ fontSize: 12, fill: '#475569' }}
              tickLine={false}
              axisLine={{ stroke: 'rgba(148, 163, 184, 0.4)' }}
            />
            <YAxis
              tick={{ fontSize: 12, fill: '#475569' }}
              tickLine={false}
              axisLine={{ stroke: 'rgba(148, 163, 184, 0.4)' }}
              allowDecimals={false}
            />
            <Tooltip
              contentStyle={{ fontSize: 13, borderRadius: 8 }}
              labelStyle={{ fontWeight: 600 }}
              formatter={(value) => [valueFormatter(value), label]}
            />
            <Line
              type='monotone'
              dataKey={dataKey}
              stroke={color}
              strokeWidth={2.5}
              dot={{ r: 3 }}
              activeDot={{ r: 5 }}
              connectNulls
              isAnimationActive={false}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
