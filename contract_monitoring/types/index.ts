// Type definitions for the PFDA Contract Monitoring System

export type UserRole = 'ADMIN' | 'STAFF' | 'TENANT'

export interface User {
  id: string
  email: string
  firstName: string
  lastName: string
  role: UserRole
  contactNumber?: string
  address?: string
  createdAt: string
  updatedAt: string
}

export type SpaceStatus = 'AVAILABLE' | 'OCCUPIED' | 'MAINTENANCE'

export interface RentalSpaceType {
  id: string
  name: string
  description?: string
  baseRatePerSqm: number
  totalSpaces: number
  createdAt: string
  updatedAt: string
}

export interface RentalSpace {
  id: string
  spaceNumber: string
  typeId: string
  type?: RentalSpaceType
  squareMeters: number
  location?: string
  status: SpaceStatus
  createdAt: string
  updatedAt: string
}

export type ContractStatus = 'ACTIVE' | 'EXPIRED' | 'TERMINATED' | 'RENEWED'

export interface Contract {
  id: string
  contractNumber: string
  tenantId: string
  tenant?: User
  rentalSpaceId: string
  rentalSpace?: RentalSpace
  startDate: string
  endDate: string
  monthlyRent: number
  securityDeposit: number
  interestRate: number
  status: ContractStatus
  qrCode?: string
  terms?: string
  notes?: string
  createdAt: string
  updatedAt: string
  payments?: Payment[]
}

export type PaymentStatus = 'PENDING' | 'PAID' | 'OVERDUE' | 'PARTIAL'

export interface Payment {
  id: string
  contractId: string
  contract?: Contract
  amount: number
  paymentDate: string
  dueDate: string
  paymentFor: string
  lateFee: number
  interest: number
  totalAmount: number
  status: PaymentStatus
  receiptNumber?: string
  paymentMethod?: string
  notes?: string
  createdAt: string
  updatedAt: string
}

export type NotificationType = 
  | 'PAYMENT_DUE' 
  | 'PAYMENT_OVERDUE' 
  | 'CONTRACT_RENEWAL' 
  | 'CONTRACT_EXPIRING'

export type NotificationStatus = 'PENDING' | 'SENT' | 'FAILED'

export interface Notification {
  id: string
  contractId: string
  contract?: Contract
  type: NotificationType
  subject: string
  message: string
  sentDate?: string
  status: NotificationStatus
  createdAt: string
}

export interface Message {
  id: string
  senderId: string
  sender?: User
  receiverId?: string
  receiver?: User
  subject?: string
  content: string
  isRead: boolean
  createdAt: string
}

export interface AuditLog {
  id: string
  userId: string
  user?: User
  action: string
  entity: string
  entityId: string
  oldValues?: string
  newValues?: string
  ipAddress?: string
  userAgent?: string
  createdAt: string
}

export interface DashboardStats {
  totalContracts: number
  activeContracts: number
  expiredContracts: number
  delinquentContracts: number
  totalRevenue: number
  pendingPayments: number
  availableSpaces: number
  occupiedSpaces: number
  recentPayments: Payment[]
  expiringContracts: Contract[]
}

export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthResponse {
  token: string
  user: User
}
